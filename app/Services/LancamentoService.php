<?php

namespace App\Services;

use App\Models\Conta;
use App\Models\EventoDominio;
use App\Models\FormaPagamento;
use App\Models\Lancamento;
use App\Models\ObrigacaoFinanceira;
use App\Models\Usuario;
use App\Models\Venda;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
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

        $tipo = $forma->obrigacaoFinanceira->direcao === 'a_receber' ? 'entrada' : 'saida';
        $valor = (float) $forma->valor_liquidado_reais;
        $descricao = $this->descricaoParaObrigacao($forma->obrigacaoFinanceira);

        // V1 (28/10/2026) — venda corrigida vale uma vez só: o lançamento leva
        // a Carteira até o valor da versão atual, descontando o que as outras
        // versões da mesma venda já lançaram. Nunca o valor cheio de novo.
        if ($forma->obrigacaoFinanceira->venda_id !== null) {
            $versoes = $this->versoesDaVenda(Venda::findOrFail($forma->obrigacaoFinanceira->venda_id));
            if ($versoes->count() > 1) {
                $diferenca = round((float) $versoes->last()->valor_bruto - $this->jaLancadoNaCarteira($versoes), 2);
                if ($diferenca == 0.0) {
                    return;
                }
                $tipo = $diferenca > 0 ? 'entrada' : 'saida';
                $valor = abs($diferenca);
                $descricao = "Venda #{$versoes->first()->id} corrigida (versão atual #{$versoes->last()->id}): ajuste";
            }
        }

        try {
            DB::transaction(function () use ($forma, $fazenda, $tipo, $valor, $descricao) {
                $conta = app(ContaService::class)->buscarOuCriarContaBovino($fazenda->titular_id);

                Lancamento::create([
                    'conta_id' => $conta->id,
                    'tipo' => $tipo,
                    'valor' => $valor,
                    // INV-008 — o dia em que o dinheiro entrou ou saiu, nunca o
                    // dia em que a varredura processou o evento.
                    'data_lancamento' => $forma->pago_em,
                    'descricao' => $descricao,
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

    /** @return Collection<int, Venda> da venda original até a versão atual, em ordem */
    private function versoesDaVenda(Venda $venda): Collection
    {
        $raiz = $venda;
        while ($raiz->original !== null) {
            $raiz = $raiz->original;
        }

        $versoes = collect([$raiz]);
        while ($versoes->last()->correcao !== null) {
            $versoes->push($versoes->last()->correcao);
        }

        return $versoes;
    }

    /** @param Collection<int, Venda> $versoes */
    private function jaLancadoNaCarteira(Collection $versoes): float
    {
        $formas = FormaPagamento::whereHas('obrigacaoFinanceira', fn ($obrigacao) => $obrigacao->whereIn('venda_id', $versoes->pluck('id')))->pluck('id');

        return (float) Lancamento::whereIn('forma_pagamento_id', $formas)->get()
            ->sum(fn (Lancamento $lancamento) => $lancamento->tipo === 'entrada' ? (float) $lancamento->valor : -(float) $lancamento->valor);
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
