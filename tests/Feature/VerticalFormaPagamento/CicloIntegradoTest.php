<?php

namespace Tests\Feature\VerticalFormaPagamento;

use App\Models\Fazenda;
use App\Models\FormaPagamento;
use App\Models\Fornecedor;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Models\Venda;
use App\Services\CompraService;
use App\Services\FormaPagamentoService;
use App\Services\VendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cenário integrado: Compra à vista + Venda a prazo com entrada, ao longo do
 * tempo, verificando o estado final consolidado — mesmo espírito do
 * CicloIntegradoTest dos 2 verticais anteriores. Não prova "liquidar()
 * funciona" isoladamente (já provado em FormaPagamentoDominioTest); prova
 * que status computado (INV-032) continua correto quando a Fazenda tem
 * várias Obrigações Financeiras em estados diferentes ao mesmo tempo.
 */
class CicloIntegradoTest extends TestCase
{
    use RefreshDatabase;

    public function test_compra_a_vista_e_venda_com_entrada_e_parcela_estado_final_correto(): void
    {
        $fazenda = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazenda, 'papel' => 'dono']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília'])->id;

        // ── 1. Compra à vista (automática, via CompraService) ──
        $resultadoCompra = app(CompraService::class)->registrar($jose, $fazenda, $fornecedor, [5000.00], '2026-01-10', 'compra-ciclo');
        $obrigacaoCompra = $resultadoCompra['obrigacao_financeira']->fresh();
        $this->assertSame('pago', $obrigacaoCompra->status, 'compra_a_vista_ja_nasce_paga');

        // ── 2. Venda (automática, via VendaService) — também à vista por padrão ──
        $venda = Venda::create([
            'fazenda_id' => $fazenda, 'chave_idempotencia' => 'venda-base',
            'animal_ids' => [], 'data_venda' => '2026-01-01 10:00:00', 'valor_bruto' => 20000.00, 'cpv' => 0, 'deducao_fiscal' => 0,
            'fiscal_e_premissa' => true, 'receita_liquida' => 20000.00,
        ]);
        // Simula uma Venda "com entrada + parcela" — declaração direta de
        // múltiplas Formas de Pagamento no ato de registrar() é a extensão
        // da frente seguinte (ver plano); aqui a Obrigação Financeira nasce
        // manualmente com esse formato pra provar que o resto do mecanismo
        // (status computado, liquidação posterior) já funciona fim a fim.
        $obrigacaoVenda = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda, 'venda_id' => $venda->id, 'direcao' => 'a_receber', 'valor' => 20000.00,
        ]);
        $entrada = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacaoVenda->id, 'nome' => 'entrada', 'unidade' => 'dinheiro',
            'valor' => 8000.00, 'data' => '2026-01-15', 'vencimento' => '2026-01-15', 'pago_em' => '2026-01-15',
        ]);
        $parcela = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacaoVenda->id, 'nome' => 'parcela única', 'unidade' => 'dinheiro',
            'valor' => 12000.00, 'data' => '2026-01-15', 'vencimento' => '2026-03-15',
        ]);

        // ── 3. Estado intermediário: uma Fazenda, duas Obrigações, estados diferentes ──
        $this->assertSame('pago', $obrigacaoCompra->fresh()->status, 'compra_nao_afetada_pela_venda');
        $this->assertSame('parcial', $obrigacaoVenda->fresh()->status, 'venda_parcialmente_paga');
        $this->assertSame(2, ObrigacaoFinanceira::where('fazenda_id', $fazenda)->count());

        // ── 4. Liquidar a parcela restante ──
        app(FormaPagamentoService::class)->liquidar($jose, $parcela->id);

        // ── 5. Estado final — soma bate (INV-031), status reflete liquidação real (INV-032) ──
        $obrigacaoVenda->refresh();
        $this->assertSame('pago', $obrigacaoVenda->status, 'venda_totalmente_liquidada');
        $somaFormas = $obrigacaoVenda->formasPagamento()->sum('valor');
        $this->assertEqualsWithDelta((float) $obrigacaoVenda->valor, (float) $somaFormas, 0.01, 'soma_bate_com_o_total_inv031');
        $this->assertSame('pago', $obrigacaoCompra->fresh()->status, 'compra_continua_intocada');
    }
}
