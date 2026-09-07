<?php

namespace App\Services;

use App\Models\EventoDominio;
use App\Models\SeparacaoVenda;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O núcleo do Vertical 8 (Separação para Venda — a ponte, 4º e último
 * canal), nascido de VERTICAL-SEPARACAO-VENDA.md, traduzindo
 * SCHEMA-CONTRATO-SEPARACAO-VENDA.md pra código real. A ponte é literal:
 * concluir() chama VendaService::registrar() de verdade — nenhuma lógica de
 * venda nova aqui. Igual GTA, a separação é declarada unilateralmente pela
 * Fazenda de origem (nenhuma Fazenda compradora a autorizar), então cabe
 * numa única transação, sem confirmação em 2 fases.
 */
class SeparacaoVendaService
{
    public function buscar(int $usuarioId, int $separacaoId): ?SeparacaoVenda
    {
        $separacao = SeparacaoVenda::find($separacaoId);
        if (! $separacao) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($separacao->fazenda_id)) {
            return null;
        }

        return $separacao;
    }

    public function registrar(int $usuarioId, int $fazendaId, array $animalIds, float $valorTotal, string $dataSeparacao, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = SeparacaoVenda::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'separacao' => $existente];
        }

        if (empty($animalIds)) {
            throw new DomainException('Uma separação para venda precisa de pelo menos um animal.');
        }

        if ($valorTotal <= 0) {
            throw new DomainException("Separação para venda exige valor_total positivo (recebido: {$valorTotal}).");
        }

        try {
            $separacao = SeparacaoVenda::create([
                'fazenda_id' => $fazendaId,
                'animal_ids' => array_values($animalIds),
                'valor_total' => $valorTotal,
                'status' => 'aberta',
                'data_separacao' => $dataSeparacao,
                'chave_idempotencia' => $chaveIdempotencia,
            ]);

            return ['reenvio_detectado' => false, 'separacao' => $separacao];
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'separacao' => SeparacaoVenda::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    public function concluir(int $usuarioId, int $separacaoId): array
    {
        $separacao = SeparacaoVenda::find($separacaoId);
        if (! $separacao) {
            throw new DomainException("SeparacaoVenda #{$separacaoId} não encontrada.");
        }

        $this->garantirRelacaoComFazenda($usuarioId, $separacao->fazenda_id);

        return DB::transaction(function () use ($usuarioId, $separacaoId) {
            $separacao = SeparacaoVenda::lockForUpdate()->findOrFail($separacaoId);

            if ($separacao->status === 'concluida') {
                return ['reenvio_detectado' => true, 'separacao' => $separacao];
            }

            if ($separacao->status !== 'aberta') {
                throw new DomainException("Conclusão de separação exige status=aberta (atual: {$separacao->status}).");
            }

            // A própria checagem interna de VendaService (Animais ativo +
            // lockForUpdate) já garante que os animais ainda pertencem à
            // Fazenda e não foram vendidos por nenhum canal — herda de graça
            // a defesa contra concorrência (mesmo mecanismo que
            // Marketplace/GTA já herdaram).
            $resultadoVenda = app(VendaService::class)->registrar(
                $usuarioId,
                $separacao->fazenda_id,
                $separacao->animal_ids,
                (float) $separacao->valor_total,
                now()->format('Y-m-d H:i:s'),
                $separacao->chave_idempotencia.':venda'
            );

            $separacao->update([
                'venda_id' => $resultadoVenda['venda']->id,
                'status' => 'concluida',
                'data_conclusao' => now(),
            ]);

            $this->registrarEvento('separacao_venda_concluida', $separacao->fazenda_id, $separacao->chave_idempotencia, [
                'tipo' => 'separacao_venda_concluida', 'separacao_venda_id' => $separacao->id, 'venda_id' => $resultadoVenda['venda']->id,
            ]);

            return ['reenvio_detectado' => false, 'separacao' => $separacao->fresh(), 'venda' => $resultadoVenda['venda']];
        });
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
