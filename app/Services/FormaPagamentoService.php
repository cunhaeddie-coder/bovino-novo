<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\FormaPagamento;
use App\Models\FormaPagamentoHistorico;
use App\Models\Usuario;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * VERTICAL-FORMA-PAGAMENTO.md §3/§6 — liquidar() e editar() de uma
 * FormaPagamento já criada por CompraService/CompraInsumoService/VendaService
 * (ou, numa frente seguinte, declarada diretamente com parcelamento). Nunca
 * cria a Obrigação Financeira em si — isso é sempre efeito do registro da
 * operação original.
 */
class FormaPagamentoService
{
    /**
     * SCHEMA-CONTRATO-FORMA-PAGAMENTO.md §2/§5 — liquidação em dinheiro
     * grava pago_em direto; em espécie dispara a mesma baixa/entrada real de
     * Animal que Venda/Compra já usam (§9 pergunta 2), nunca só um número no
     * campo valor. Idempotente: liquidar uma Forma já paga não reprocessa
     * nem lança erro, mesmo espírito de reenvio dos 3 Services existentes.
     */
    public function liquidar(int $usuarioId, int $formaPagamentoId, ?int $animalId = null, ?float $cotacaoArroba = null): array
    {
        $forma = FormaPagamento::with('obrigacaoFinanceira')->findOrFail($formaPagamentoId);
        $fazendaId = $forma->obrigacaoFinanceira->fazenda_id;
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if ($forma->pago_em !== null) {
            return ['ja_liquidada' => true, 'forma_pagamento' => $forma];
        }

        if ($forma->unidade === 'arroba' && $cotacaoArroba === null) {
            throw new DomainException('Liquidação de Forma de Pagamento em arroba exige cotacaoArroba.');
        }
        if ($forma->meio_liquidacao === 'especie' && $animalId === null) {
            throw new DomainException('Liquidação em espécie exige animalId.');
        }

        return DB::transaction(function () use ($forma, $fazendaId, $animalId, $cotacaoArroba) {
            // lockForUpdate re-checa pago_em sob concorrência real — duas
            // liquidações concorrentes da mesma Forma de Pagamento nunca
            // processam a mesma liquidação duas vezes (mesma classe de
            // achado do Spike 007/Ataque A, ainda não reexecutada aqui em
            // MySQL real — ver plano, spike de concorrência fica pra depois).
            $forma = FormaPagamento::where('id', $forma->id)->lockForUpdate()->firstOrFail();
            if ($forma->pago_em !== null) {
                return ['ja_liquidada' => true, 'forma_pagamento' => $forma];
            }

            $hoje = now()->toDateString();
            $valorLiquidadoReais = $forma->unidade === 'arroba'
                ? round((float) $forma->valor * $cotacaoArroba, 2)
                : (float) $forma->valor;

            $dados = [
                'pago_em' => $hoje,
                'valor_liquidado_reais' => $valorLiquidadoReais,
            ];
            if ($forma->unidade === 'arroba') {
                $dados['cotacao_arroba_na_liquidacao'] = $cotacaoArroba;
            }
            if ($forma->meio_liquidacao === 'especie') {
                $dados['animal_id'] = $this->liquidarEmEspecie($forma, $fazendaId, $animalId, $valorLiquidadoReais, $hoje);
            }

            $forma->update($dados);

            // VERTICAL-FORMA-PAGAMENTO.md §4 — evento novo (mesmo mecanismo
            // genérico de outbox já usado pelos 3 Services), dentro da mesma
            // transação da liquidação. Chave de idempotência do EVENTO
            // escopada por FormaPagamento (nunca duplica mesmo se liquidar()
            // for chamado de novo depois — mas isso já não deveria acontecer,
            // o guard de pago_em acima intercepta antes).
            $this->registrarEvento('forma_pagamento_liquidada', $fazendaId, "forma-pagamento-{$forma->id}", [
                'tipo' => 'forma_pagamento_liquidada', 'forma_pagamento_id' => $forma->id,
                'obrigacao_financeira_id' => $forma->obrigacao_financeira_id, 'fazenda_id' => $fazendaId,
            ]);

            return ['ja_liquidada' => false, 'forma_pagamento' => $forma->fresh()];
        });
    }

    /**
     * SCHEMA-CONTRATO-FORMA-PAGAMENTO.md §5 — a_pagar (Fazenda deve) liquidado
     * em espécie é a Fazenda ENTREGANDO um animal (mesmo mecanismo de saída
     * que VendaService usa — status='vendido'). a_receber liquidado em
     * espécie é a Fazenda RECEBENDO um animal (mesmo mecanismo de entrada que
     * CompraService usa — Animal novo, sem lote, custo_aquisicao = valor
     * liquidado). Retorna o animal_id efetivo (o recebido, quando criado).
     */
    private function liquidarEmEspecie(FormaPagamento $forma, int $fazendaId, int $animalId, float $valorLiquidadoReais, string $data): int
    {
        $direcao = $forma->obrigacaoFinanceira->direcao;

        if ($direcao === 'a_pagar') {
            $animal = Animal::where('id', $animalId)->where('fazenda_id', $fazendaId)->where('status', 'ativo')->lockForUpdate()->first();
            if (! $animal) {
                throw new DomainException("Animal #{$animalId} não encontrado, não pertence a esta Fazenda, ou já não está ativo.");
            }
            $animal->update(['status' => 'vendido', 'data_saida' => $data]);

            return $animal->id;
        }

        // a_receber — o "animalId" recebido do chamador aqui é só um valor
        // informativo de referência externa (não existe um Animal do
        // sistema pra vincular antes de criado); o Animal novo nasce agora,
        // mesmo padrão de CompraService::registrar().
        $animal = Animal::create([
            'fazenda_id' => $fazendaId,
            'lote_id' => null,
            'custo_aquisicao' => $valorLiquidadoReais,
            'status' => 'ativo',
        ]);

        return $animal->id;
    }

    /**
     * SCHEMA-CONTRATO-FORMA-PAGAMENTO.md §6 — UPDATE direto é aceitável aqui
     * (diferente de INV-026), mas nunca sem gravar o estado ANTERIOR em
     * formas_pagamento_historico primeiro. INV-031 — a soma das Formas de
     * Pagamento da Obrigação nunca pode passar a divergir do valor total por
     * causa de uma edição.
     */
    public function editar(int $usuarioId, int $formaPagamentoId, array $novosDados): FormaPagamento
    {
        $forma = FormaPagamento::with('obrigacaoFinanceira.formasPagamento')->findOrFail($formaPagamentoId);
        $fazendaId = $forma->obrigacaoFinanceira->fazenda_id;
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if ($forma->pago_em !== null) {
            throw new DomainException('Forma de Pagamento já liquidada não pode ser editada — o histórico real da liquidação é definitivo.');
        }

        if (array_key_exists('valor', $novosDados)) {
            $obrigacao = $forma->obrigacaoFinanceira;
            $somaOutras = $obrigacao->formasPagamento->where('id', '!=', $forma->id)->sum('valor');
            if (round((float) $somaOutras + (float) $novosDados['valor'], 2) > round((float) $obrigacao->valor, 2)) {
                throw new DomainException('A soma das Formas de Pagamento não pode ultrapassar o valor total da operação (INV-031).');
            }
        }

        return DB::transaction(function () use ($forma, $usuarioId, $novosDados) {
            FormaPagamentoHistorico::create([
                'forma_pagamento_id' => $forma->id,
                'nome' => $forma->nome,
                'valor' => $forma->valor,
                'unidade' => $forma->unidade,
                'vencimento' => $forma->vencimento,
                'alterado_em' => now(),
                'alterado_por' => $usuarioId,
            ]);

            $forma->update(array_intersect_key($novosDados, array_flip(['nome', 'valor', 'unidade', 'vencimento'])));

            return $forma->fresh();
        });
    }

    private function garantirRelacaoComFazenda(int $usuarioId, int $fazendaId): void
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($fazendaId)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com a Fazenda {$fazendaId}.");
        }
    }

    // Mesmo padrão duplicado nos 3 Services existentes (registrarEvento()).
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
}
