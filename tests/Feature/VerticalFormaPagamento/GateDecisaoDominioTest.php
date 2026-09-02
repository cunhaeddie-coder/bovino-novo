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
 * GATE-DECISAO-DOMINIO-VENCIMENTO.md — trava as decisões centrais de
 * Forma de Pagamento como regressão permanente, mesmo padrão de
 * GateDecisaoDominioTest dos 2 verticais anteriores.
 */
class GateDecisaoDominioTest extends TestCase
{
    use RefreshDatabase;

    private int $fazenda;

    private int $jose;

    private ObrigacaoFinanceira $obrigacao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fazenda = Fazenda::create(['nome' => 'A'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília'])->id;
        $compra = Compra::create([
            'fazenda_id' => $this->fazenda, 'fornecedor_id' => $fornecedor,
            'chave_idempotencia' => 'x', 'data_compra' => '2026-01-01', 'valor_total' => 1000,
        ]);
        $this->obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $this->fazenda, 'compra_id' => $compra->id, 'direcao' => 'a_pagar', 'valor' => 1000,
        ]);
    }

    /** Liquidar 2x a mesma Forma de Pagamento é idempotente, nunca duplica nem lança erro. */
    public function test_liquidar_duas_vezes_e_idempotente(): void
    {
        $forma = FormaPagamento::create([
            'obrigacao_financeira_id' => $this->obrigacao->id, 'nome' => 'à vista', 'unidade' => 'dinheiro',
            'valor' => 1000, 'data' => '2026-01-01', 'vencimento' => '2026-01-01',
        ]);
        $service = app(FormaPagamentoService::class);

        $primeira = $service->liquidar($this->jose, $forma->id);
        $this->assertFalse($primeira['ja_liquidada']);

        $segunda = $service->liquidar($this->jose, $forma->id);
        $this->assertTrue($segunda['ja_liquidada']);
        $this->assertSame(
            $primeira['forma_pagamento']->pago_em->toDateString(),
            $segunda['forma_pagamento']->pago_em->toDateString(),
            'nao_reprocessou_pago_em_igual'
        );
    }

    public function test_liquidar_arroba_sem_cotacao_e_recusado(): void
    {
        $forma = FormaPagamento::create([
            'obrigacao_financeira_id' => $this->obrigacao->id, 'nome' => 'x', 'unidade' => 'arroba',
            'valor' => 100, 'data' => '2026-01-01', 'vencimento' => '2027-01-01',
        ]);

        $this->assertThrows(
            fn () => app(FormaPagamentoService::class)->liquidar($this->jose, $forma->id),
            DomainException::class
        );
        $this->assertNull($forma->fresh()->pago_em);
    }

    public function test_liquidar_especie_sem_animal_id_e_recusado(): void
    {
        $forma = FormaPagamento::create([
            'obrigacao_financeira_id' => $this->obrigacao->id, 'nome' => 'x', 'unidade' => 'dinheiro',
            'valor' => 1000, 'data' => '2026-01-01', 'vencimento' => '2026-01-01', 'meio_liquidacao' => 'especie',
        ]);

        $this->assertThrows(
            fn () => app(FormaPagamentoService::class)->liquidar($this->jose, $forma->id),
            DomainException::class
        );
    }
}
