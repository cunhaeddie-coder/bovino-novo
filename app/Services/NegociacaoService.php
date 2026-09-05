<?php

namespace App\Services;

use App\Models\Anuncio;
use App\Models\EventoDominio;
use App\Models\Fornecedor;
use App\Models\Negociacao;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O núcleo do Vertical 4 (Marketplace), nascido de VERTICAL-MARKETPLACE.md,
 * traduzindo SCHEMA-CONTRATO-MARKETPLACE.md pra código real. A ponte é
 * literal (decisão do produtor): concluir uma Negociação reaproveita
 * VendaService::registrar()/CompraService::registrar() reais — nenhuma
 * lógica de venda/compra nova aqui.
 *
 * Confirmação em 2 fases (§5, corrigido antes do código): VendaService exige
 * autorização da Fazenda vendedora, CompraService da compradora — nenhum
 * usuário real tem relação com as duas. confirmarVendedor()/
 * confirmarComprador() rodam em transações separadas, cada uma autorizada
 * pelo lado certo; a Negociação só conclui quando as duas existirem.
 */
class NegociacaoService
{
    public function buscar(int $usuarioId, int $negociacaoId): ?Negociacao
    {
        $negociacao = Negociacao::with('anuncio')->find($negociacaoId);
        if (! $negociacao) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        $temRelacao = $usuario->temRelacaoComFazenda($negociacao->fazenda_compradora_id)
            || $usuario->temRelacaoComFazenda($negociacao->anuncio->fazenda_id);

        return $temRelacao ? $negociacao : null;
    }

    public function propor(int $usuarioId, int $anuncioId, int $fazendaCompradoraId, float $precoProposto, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaCompradoraId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = Negociacao::where('fazenda_compradora_id', $fazendaCompradoraId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'negociacao' => $existente];
        }

        // SCHEMA-CONTRATO-MARKETPLACE.md §5 (INV-023) — a checagem correta é
        // contra o status real do Anúncio, sempre vivo (nunca um campo que o
        // produtor precisa lembrar de atualizar).
        $anuncio = Anuncio::where('id', $anuncioId)->where('status', 'ativo')->first();
        if (! $anuncio) {
            throw new DomainException("Anúncio #{$anuncioId} não encontrado ou não está ativo.");
        }

        if ($precoProposto <= 0) {
            throw new DomainException("Negociação exige preco_proposto positivo (recebido: {$precoProposto}).");
        }

        try {
            $negociacao = Negociacao::create([
                'anuncio_id' => $anuncioId,
                'fazenda_compradora_id' => $fazendaCompradoraId,
                'preco_proposto' => $precoProposto,
                'status' => 'proposta',
                'chave_idempotencia' => $chaveIdempotencia,
            ]);

            return ['reenvio_detectado' => false, 'negociacao' => $negociacao];
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'negociacao' => Negociacao::where('fazenda_compradora_id', $fazendaCompradoraId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    public function aceitar(int $usuarioId, int $negociacaoId): array
    {
        $negociacao = Negociacao::with('anuncio')->find($negociacaoId);
        if (! $negociacao) {
            throw new DomainException("Negociação #{$negociacaoId} não encontrada.");
        }

        $this->garantirRelacaoComFazenda($usuarioId, $negociacao->anuncio->fazenda_id);

        if ($negociacao->status !== 'proposta') {
            throw new DomainException("Negociação #{$negociacaoId} só pode ser aceita a partir de status=proposta (atual: {$negociacao->status}).");
        }

        $negociacao->update(['status' => 'aceita']);

        return ['negociacao' => $negociacao];
    }

    public function confirmarVendedor(int $usuarioId, int $negociacaoId): array
    {
        $negociacao = Negociacao::with('anuncio')->find($negociacaoId);
        if (! $negociacao) {
            throw new DomainException("Negociação #{$negociacaoId} não encontrada.");
        }

        $fazendaVendedoraId = $negociacao->anuncio->fazenda_id;
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaVendedoraId);

        return DB::transaction(function () use ($usuarioId, $negociacaoId, $fazendaVendedoraId) {
            $negociacao = Negociacao::with('anuncio.animais')->lockForUpdate()->findOrFail($negociacaoId);

            if ($negociacao->confirmado_vendedor_em !== null) {
                return ['reenvio_detectado' => true, 'negociacao' => $negociacao];
            }

            if ($negociacao->status !== 'aceita') {
                throw new DomainException("Confirmação do vendedor exige status=aceita (atual: {$negociacao->status}).");
            }

            $animalIds = $negociacao->anuncio->animais->pluck('id')->all();

            // A própria checagem interna de VendaService (Animais ativo +
            // lockForUpdate) já garante que os animais ainda pertencem à
            // Fazenda e não foram vendidos por nenhum canal — herda de graça
            // a defesa contra uma 2ª Negociação concorrente vendendo os
            // mesmos animais (SCHEMA-CONTRATO-MARKETPLACE.md §5).
            // GATE-DECISAO-DOMINIO-DATA-HORA.md — o momento da confirmação
            // do vendedor É o momento real do fato Venda neste canal (não há
            // uma data "combinada" separada da confirmação, diferente do
            // registro direto de Venda fora do Marketplace).
            $resultadoVenda = app(VendaService::class)->registrar(
                $usuarioId,
                $fazendaVendedoraId,
                $animalIds,
                (float) $negociacao->preco_proposto,
                now()->format('Y-m-d H:i:s'),
                $negociacao->chave_idempotencia.':venda'
            );

            $negociacao->update([
                'venda_id' => $resultadoVenda['venda']->id,
                'confirmado_vendedor_em' => now(),
            ]);

            // INV-023 — Anúncio nunca continua ativo depois que o item que
            // ele anuncia foi vendido. GATE-DECISAO-DOMINIO-DATA-HORA.md —
            // encerrado_em com data e hora reais, não só o dia.
            $negociacao->anuncio->update(['status' => 'vendido', 'encerrado_em' => now()]);

            $this->concluirSeAmbosConfirmaram($negociacao);

            return ['reenvio_detectado' => false, 'negociacao' => $negociacao->fresh(), 'venda' => $resultadoVenda['venda']];
        });
    }

    public function confirmarComprador(int $usuarioId, int $negociacaoId): array
    {
        $negociacao = Negociacao::find($negociacaoId);
        if (! $negociacao) {
            throw new DomainException("Negociação #{$negociacaoId} não encontrada.");
        }

        $this->garantirRelacaoComFazenda($usuarioId, $negociacao->fazenda_compradora_id);

        return DB::transaction(function () use ($usuarioId, $negociacaoId) {
            $negociacao = Negociacao::with('anuncio.animais', 'anuncio.fazenda')->lockForUpdate()->findOrFail($negociacaoId);

            if ($negociacao->confirmado_comprador_em !== null) {
                return ['reenvio_detectado' => true, 'negociacao' => $negociacao];
            }

            if ($negociacao->status !== 'aceita') {
                throw new DomainException("Confirmação do comprador exige status=aceita (atual: {$negociacao->status}).");
            }

            $fornecedor = $this->buscarOuCriarFornecedorParaFazenda($negociacao->anuncio->fazenda_id, $negociacao->anuncio->fazenda->nome);

            $valoresPorAnimal = $this->dividirPrecoIgualmente(
                (float) $negociacao->preco_proposto,
                $negociacao->anuncio->animais->count()
            );

            // GATE-DECISAO-DOMINIO-DATA-HORA.md — mesmo raciocínio do lado
            // vendedor: o momento da confirmação do comprador É o momento
            // real do fato Compra neste canal.
            $resultadoCompra = app(CompraService::class)->registrar(
                $usuarioId,
                $negociacao->fazenda_compradora_id,
                $fornecedor->id,
                $valoresPorAnimal,
                now()->format('Y-m-d H:i:s'),
                $negociacao->chave_idempotencia.':compra'
            );

            $negociacao->update([
                'compra_id' => $resultadoCompra['compra']->id,
                'confirmado_comprador_em' => now(),
            ]);

            $this->concluirSeAmbosConfirmaram($negociacao);

            return ['reenvio_detectado' => false, 'negociacao' => $negociacao->fresh(), 'compra' => $resultadoCompra['compra']];
        });
    }

    private function concluirSeAmbosConfirmaram(Negociacao $negociacao): void
    {
        $negociacao->refresh();

        if ($negociacao->confirmado_vendedor_em !== null && $negociacao->confirmado_comprador_em !== null && $negociacao->status !== 'concluida') {
            $negociacao->update(['status' => 'concluida', 'concluida_em' => now()]);

            $this->registrarEvento('negociacao_concluida', $negociacao->fazenda_compradora_id, $negociacao->chave_idempotencia, [
                'tipo' => 'negociacao_concluida',
                'negociacao_id' => $negociacao->id,
                'venda_id' => $negociacao->venda_id,
                'compra_id' => $negociacao->compra_id,
            ]);
        }
    }

    /**
     * SCHEMA-CONTRATO-MARKETPLACE.md §5 — divisão igual, arredondada, com o
     * último animal absorvendo o resto do arredondamento pra bater exato com
     * o total. Não é fórmula de precificação: o Anúncio só carrega 1 preço
     * pro lote inteiro (decisão já fechada), essa é a única divisão possível
     * a partir de um dado que só existe como total.
     *
     * @return float[]
     */
    private function dividirPrecoIgualmente(float $precoTotal, int $quantidadeAnimais): array
    {
        $valorBase = round($precoTotal / $quantidadeAnimais, 2);
        $valores = array_fill(0, $quantidadeAnimais - 1, $valorBase);
        $valores[] = round($precoTotal - ($valorBase * ($quantidadeAnimais - 1)), 2);

        return $valores;
    }

    // SCHEMA-CONTRATO-MARKETPLACE.md §1 — Fornecedor representando a Fazenda
    // vendedora, sempre reaproveitado (nunca duplicado por Fazenda).
    private function buscarOuCriarFornecedorParaFazenda(int $fazendaId, string $nomeFazenda): Fornecedor
    {
        try {
            return Fornecedor::firstOrCreate(['fazenda_id' => $fazendaId], ['nome' => $nomeFazenda]);
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return Fornecedor::where('fazenda_id', $fazendaId)->firstOrFail();
            }
            throw $e;
        }
    }

    private function garantirRelacaoComFazenda(int $usuarioId, int $fazendaId): void
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($fazendaId)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com a Fazenda {$fazendaId}.");
        }
    }

    private function registrarEvento(string $tipo, int $fazendaId, string $chaveIdempotenciaDoFato, array $payload): EventoDominio
    {
        return EventoDominio::create([
            'tipo' => $tipo,
            'fazenda_id' => $fazendaId,
            'chave_idempotencia' => $chaveIdempotenciaDoFato.':evento',
            'payload' => $payload,
            'status_consequencia' => 'pendente',
        ]);
    }

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
