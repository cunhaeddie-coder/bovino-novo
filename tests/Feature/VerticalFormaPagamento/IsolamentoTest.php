<?php

namespace Tests\Feature\VerticalFormaPagamento;

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

/**
 * INV-029 aplicado a Forma de Pagamento — mesmo padrão de
 * CompraIsolamentoTest/CompraInsumoIsolamentoTest. Não é lógica dependente
 * de banco (Princípio 4b não se aplica aqui), SQLite sequencial é ambiente
 * adequado. Não mistura concorrência (fora desta rodada, ver plano).
 */
class IsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_com_papel_so_na_fazenda_b_nao_liquida_forma_de_pagamento_da_fazenda_a(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $donoDeB = Usuario::create(['nome' => 'Dono de B'])->id;
        Papel::create(['usuario_id' => $donoDeB, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília'])->id;

        $compra = Compra::create([
            'fazenda_id' => $fazendaA, 'fornecedor_id' => $fornecedor,
            'chave_idempotencia' => 'x', 'data_compra' => '2026-01-01', 'valor_total' => 500,
        ]);
        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazendaA, 'compra_id' => $compra->id, 'direcao' => 'a_pagar', 'valor' => 500,
        ]);
        $forma = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'x', 'unidade' => 'dinheiro',
            'valor' => 500, 'data' => '2026-01-01', 'vencimento' => '2026-02-01',
        ]);

        $this->assertThrows(
            fn () => app(FormaPagamentoService::class)->liquidar($donoDeB, $forma->id),
            DomainException::class
        );
        $this->assertNull($forma->fresh()->pago_em, 'nenhum_efeito_cruzou_a_fronteira_da_fazenda');
    }

    public function test_formas_de_pagamento_de_fazendas_diferentes_ficam_em_obrigacoes_independentes(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $fornecedor = Fornecedor::create(['nome' => 'Marília'])->id;

        $compraA = Compra::create([
            'fazenda_id' => $fazendaA, 'fornecedor_id' => $fornecedor,
            'chave_idempotencia' => 'a', 'data_compra' => '2026-01-01', 'valor_total' => 500,
        ]);
        $compraB = Compra::create([
            'fazenda_id' => $fazendaB, 'fornecedor_id' => $fornecedor,
            'chave_idempotencia' => 'b', 'data_compra' => '2026-01-01', 'valor_total' => 700,
        ]);
        $obrigacaoA = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazendaA, 'compra_id' => $compraA->id, 'direcao' => 'a_pagar', 'valor' => 500,
        ]);
        $obrigacaoB = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazendaB, 'compra_id' => $compraB->id, 'direcao' => 'a_pagar', 'valor' => 700,
        ]);
        FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacaoA->id, 'nome' => 'x', 'unidade' => 'dinheiro',
            'valor' => 500, 'data' => '2026-01-01', 'vencimento' => '2026-02-01', 'pago_em' => '2026-02-01',
        ]);
        FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacaoB->id, 'nome' => 'y', 'unidade' => 'dinheiro',
            'valor' => 700, 'data' => '2026-01-01', 'vencimento' => '2026-02-01',
        ]);

        $this->assertSame('pago', $obrigacaoA->fresh()->status);
        $this->assertSame('pendente', $obrigacaoB->fresh()->status, 'estado_de_a_nunca_vaza_pra_b');
        $this->assertSame(1, $obrigacaoA->fresh()->formasPagamento()->count());
        $this->assertSame(1, $obrigacaoB->fresh()->formasPagamento()->count());
    }
}
