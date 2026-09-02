<?php

namespace Tests\Feature\VerticalFormaPagamento;

use App\Models\Animal;
use App\Models\Compra;
use App\Models\Fazenda;
use App\Models\FormaPagamento;
use App\Models\Fornecedor;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\FormaPagamentoService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormaPagamentoFronteirasTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_sem_relacao_com_a_fazenda_nao_pode_liquidar(): void
    {
        $fazenda = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $intruso = Usuario::create(['nome' => 'Intruso'])->id; // sem Papel na Fazenda
        $fornecedor = Fornecedor::create(['nome' => 'Marília'])->id;
        $compra = Compra::create([
            'fazenda_id' => $fazenda, 'fornecedor_id' => $fornecedor,
            'chave_idempotencia' => 'x', 'data_compra' => '2026-01-01', 'valor_total' => 500,
        ]);
        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda, 'compra_id' => $compra->id, 'direcao' => 'a_pagar', 'valor' => 500,
        ]);
        $forma = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'x', 'unidade' => 'dinheiro',
            'valor' => 500, 'data' => '2026-01-01', 'vencimento' => '2026-02-01',
        ]);

        $this->assertThrows(
            fn () => app(FormaPagamentoService::class)->liquidar($intruso, $forma->id),
            DomainException::class
        );
        $this->assertNull($forma->fresh()->pago_em, 'nada_foi_liquidado');
    }

    public function test_liquidacao_em_especie_a_pagar_recusa_animal_de_outra_fazenda(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília'])->id;

        $animalDaOutraFazenda = Animal::create(['fazenda_id' => $fazendaB, 'lote_id' => null, 'custo_aquisicao' => 100, 'status' => 'ativo']);

        $compra = Compra::create([
            'fazenda_id' => $fazendaA, 'fornecedor_id' => $fornecedor,
            'chave_idempotencia' => 'x', 'data_compra' => '2026-01-01', 'valor_total' => 500,
        ]);
        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazendaA, 'compra_id' => $compra->id, 'direcao' => 'a_pagar', 'valor' => 500,
        ]);
        $forma = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'x', 'unidade' => 'dinheiro',
            'valor' => 500, 'data' => '2026-01-01', 'vencimento' => '2026-02-01', 'meio_liquidacao' => 'especie',
        ]);

        $this->assertThrows(
            fn () => app(FormaPagamentoService::class)->liquidar($jose, $forma->id, animalId: $animalDaOutraFazenda->id),
            DomainException::class
        );
    }

    public function test_editar_forma_de_pagamento_ja_liquidada_e_recusado(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazenda, 'papel' => 'dono']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília'])->id;
        $compra = Compra::create([
            'fazenda_id' => $fazenda, 'fornecedor_id' => $fornecedor,
            'chave_idempotencia' => 'x', 'data_compra' => '2026-01-01', 'valor_total' => 500,
        ]);
        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda, 'compra_id' => $compra->id, 'direcao' => 'a_pagar', 'valor' => 500,
        ]);
        $forma = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'à vista', 'unidade' => 'dinheiro',
            'valor' => 500, 'data' => '2026-01-01', 'vencimento' => '2026-01-01', 'pago_em' => '2026-01-01',
        ]);

        $this->assertThrows(
            fn () => app(FormaPagamentoService::class)->editar($jose, $forma->id, ['valor' => 999]),
            DomainException::class
        );
    }

    public function test_editar_recusa_se_soma_das_formas_ultrapassar_o_total_inv031(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazenda, 'papel' => 'dono']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília'])->id;
        $compra = Compra::create([
            'fazenda_id' => $fazenda, 'fornecedor_id' => $fornecedor,
            'chave_idempotencia' => 'x', 'data_compra' => '2026-01-01', 'valor_total' => 1000,
        ]);
        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda, 'compra_id' => $compra->id, 'direcao' => 'a_pagar', 'valor' => 1000,
        ]);
        $entrada = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'entrada', 'unidade' => 'dinheiro',
            'valor' => 400, 'data' => '2026-01-01', 'vencimento' => '2026-01-01', 'pago_em' => '2026-01-01',
        ]);
        $parcela = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'parcela', 'unidade' => 'dinheiro',
            'valor' => 600, 'data' => '2026-01-01', 'vencimento' => '2026-02-01',
        ]);

        // 400 (já paga) + 700 (edição pretendida) = 1100 > 1000 do total — recusado.
        $this->assertThrows(
            fn () => app(FormaPagamentoService::class)->editar($jose, $parcela->id, ['valor' => 700]),
            DomainException::class
        );
        $this->assertEqualsWithDelta(600.00, (float) $parcela->fresh()->valor, 0.01, 'valor_original_intocado');
    }
}
