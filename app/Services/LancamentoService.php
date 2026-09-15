<?php

namespace App\Services;

use App\Models\Conta;
use App\Models\EventoDominio;
use App\Models\FormaPagamento;
use App\Models\Lancamento;
use App\Models\ObrigacaoFinanceira;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA-CONTRATO-CARTEIRA.md — núcleo do Vertical 27 (Carteira/Conta),
 * parte 2. `lancarManual()` é a única ação direta do usuário; o consumidor
 * de `forma_pagamento_liquidada` (chamado por OutboxService::processar())
 * é a outra origem, nunca acionada por uma rota.
 */
class LancamentoService
{
    public function lancarManual(int $usuarioId, int $contaId, string $tipo, float $valor, string $dataLancamento, string $descricao, string $chaveIdempotencia): array
    {
        $conta = Conta::findOrFail($contaId);

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComTitular($conta->titular_id)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com o Titular {$conta->titular_id}.");
        }

        // SCHEMA-CONTRATO-CARTEIRA.md §3 / INV-054 — só o consumidor de
        // outbox escreve na Conta 'bovino'.
        if ($conta->tipo === 'bovino') {
            throw new DomainException('Lançamento manual não é permitido em Conta do tipo bovino — ela só reflete o que o próprio Bovino Novo processa.');
        }

        if (! in_array($tipo, ['entrada', 'saida'], true)) {
            throw new DomainException("tipo precisa ser 'entrada' ou 'saida' (recebido: {$tipo}).");
        }

        if ($valor <= 0) {
            throw new DomainException("Lançamento exige valor positivo (recebido: {$valor}).");
        }

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = Lancamento::where('conta_id', $contaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'lancamento' => $existente];
        }

        try {
            $lancamento = Lancamento::create([
                'conta_id' => $contaId,
                'tipo' => $tipo,
                'valor' => $valor,
                'data_lancamento' => $dataLancamento,
                'descricao' => $descricao,
                'origem' => 'manual',
                'forma_pagamento_id' => null,
                'chave_idempotencia' => $chaveIdempotencia,
            ]);

            return ['reenvio_detectado' => false, 'lancamento' => $lancamento];
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'lancamento' => Lancamento::where('conta_id', $contaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    // SCHEMA-CONTRATO-CARTEIRA.md §4 — chamado por OutboxService::processar()
    // pra eventos tipo='forma_pagamento_liquidada'. Nunca lança exceção pra
    // "Fazenda sem Titular" — é ausência de capacidade, não erro (mesmo
    // espírito do gate de KYC).
    public function processarLiquidacao(EventoDominio $evento): void
    {
        $formaPagamentoId = $evento->payload['forma_pagamento_id'];

        $forma = FormaPagamento::with('obrigacaoFinanceira.fazenda')->find($formaPagamentoId);
        $fazenda = $forma?->obrigacaoFinanceira?->fazenda;

        if ($fazenda === null || $fazenda->titular_id === null) {
            return;
        }

        try {
            DB::transaction(function () use ($forma, $fazenda) {
                $conta = app(ContaService::class)->buscarOuCriarContaBovino($fazenda->titular_id);

                Lancamento::create([
                    'conta_id' => $conta->id,
                    'tipo' => $forma->obrigacaoFinanceira->direcao === 'a_receber' ? 'entrada' : 'saida',
                    'valor' => $forma->valor_liquidado_reais,
                    'data_lancamento' => now(),
                    'descricao' => $this->descricaoParaObrigacao($forma->obrigacaoFinanceira),
                    'origem' => 'automatico',
                    'forma_pagamento_id' => $forma->id,
                    'chave_idempotencia' => "forma-pagamento-{$forma->id}",
                ]);
            });
        } catch (QueryException $e) {
            if (! $this->violacaoDeUnicidade($e)) {
                throw $e;
            }
            // Reprocessamento do mesmo evento (varredura + fila disputando
            // o mesmo EventoDominio) — o Lançamento já existe, nada a fazer.
        }
    }

    private function descricaoParaObrigacao(ObrigacaoFinanceira $obrigacao): string
    {
        return match (true) {
            $obrigacao->venda_id !== null => "Venda #{$obrigacao->venda_id} liquidada",
            $obrigacao->compra_id !== null => "Compra #{$obrigacao->compra_id} liquidada",
            $obrigacao->compra_insumo_id !== null => "Compra de Insumo #{$obrigacao->compra_insumo_id} liquidada",
            $obrigacao->folha_pagamento_id !== null => "Folha de Pagamento #{$obrigacao->folha_pagamento_id} liquidada",
            $obrigacao->parcela_arrendamento_id !== null => "Parcela de Arrendamento #{$obrigacao->parcela_arrendamento_id} liquidada",
            $obrigacao->ordem_frete_id !== null => "Frete #{$obrigacao->ordem_frete_id} liquidado",
            default => "Obrigação Financeira #{$obrigacao->id} liquidada",
        };
    }

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
