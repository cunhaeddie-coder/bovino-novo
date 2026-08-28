<?php

namespace Tests\Feature\VerticalCompraInsumo;

use App\Models\CompraInsumoItem;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Insumo;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\CompraInsumoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cenário integrado: duas Compras de Insumo ao longo do tempo — primeira
 * compra + segunda compra (reposição de um Insumo já existente + item novo)
 * → estado final utilizável. Consumo de Insumo está fora de escopo deste
 * vertical (SONDAGEM-VERTICAL-2.md §2) — o "estado posterior utilizável"
 * aqui não é outro fato de domínio consumindo o resultado (como Venda
 * consome Compra de Animal via CPV), é a pergunta que LAB-FA-015 já provou
 * que o Atual erra: depois de várias compras reais, o valor de estoque é
 * corretamente computável, ou fica silenciosamente R$0,00 como lá?
 *
 * Deliberadamente sem concorrência aqui — já provada em laboratório próprio
 * (MySQL real, INV-030, 15/15, 4 reproduções).
 */
class CicloIntegradoTest extends TestCase
{
    use RefreshDatabase;

    public function test_duas_compras_ao_longo_do_tempo_valor_de_estoque_computavel_ao_final(): void
    {
        $compras = app(CompraInsumoService::class);

        $fazenda = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazenda, 'papel' => 'dono']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília'])->id;

        // ── 1. Primeira Compra — LAB-SA-003: Sal + Fosbovi, entrega única ──
        $resultado1 = $compras->registrar(
            $jose, $fazenda, $fornecedor,
            [
                ['insumo_novo' => ['nome' => 'Sal Branco 25kg'], 'quantidade' => 80, 'valor_unitario' => 17.99],
                ['insumo_novo' => ['nome' => 'Fosbovi Advance 25kg'], 'quantidade' => 10, 'valor_unitario' => 220.00],
            ],
            '2026-01-20', 'compra-insumo-1'
        );

        $this->assertFalse($resultado1['reenvio_detectado']);
        $sal = Insumo::where('fazenda_id', $fazenda)->where('nome', 'Sal Branco 25kg')->first();
        $fosbovi = Insumo::where('fazenda_id', $fazenda)->where('nome', 'Fosbovi Advance 25kg')->first();
        $valorTotal1 = 80 * 17.99 + 10 * 220.00;

        $this->assertEqualsWithDelta($valorTotal1, (float) $resultado1['compra']->fresh()->valor_total, 0.01, 'primeira_compra_completa');
        $this->assertSame(1, ObrigacaoFinanceira::where('compra_insumo_id', $resultado1['compra']->id)->count(), 'obrigacao_1_completa');
        $eventoCompra1 = EventoDominio::where('fazenda_id', $fazenda)->where('tipo', 'compra_insumo_concluida')->first();
        $this->assertNotNull($eventoCompra1, 'evento_1_completo');

        // ── 2. Segunda Compra, dias depois — reposição de Sal (preço novo) + Vermífugo novo (LAB-SA-016) ──
        $resultado2 = $compras->registrar(
            $jose, $fazenda, $fornecedor,
            [
                ['insumo_id' => $sal->id, 'quantidade' => 95, 'valor_unitario' => 18.00],
                ['insumo_novo' => ['nome' => 'Vermífugo Dose Única'], 'quantidade' => 20, 'valor_unitario' => 32.00],
            ],
            '2026-03-05', 'compra-insumo-2'
        );

        $this->assertFalse($resultado2['reenvio_detectado']);
        $vermifugo = Insumo::where('fazenda_id', $fazenda)->where('nome', 'Vermífugo Dose Única')->first();

        // ── 3. Reposição soma, não substitui — Fosbovi (não tocado na 2ª compra) permanece intocado ──
        $this->assertEqualsWithDelta(80 + 95, (float) $sal->fresh()->quantidade, 0.01, 'sal_quantidade_soma_das_duas_compras');
        $this->assertEqualsWithDelta(18.00, (float) $sal->fresh()->valor_referencia, 0.01, 'sal_valor_referencia_e_o_mais_recente');
        $this->assertEqualsWithDelta(10, (float) $fosbovi->fresh()->quantidade, 0.01, 'fosbovi_intocado_pela_segunda_compra');
        $this->assertEqualsWithDelta(220.00, (float) $fosbovi->fresh()->valor_referencia, 0.01, 'fosbovi_valor_referencia_intocado');
        $this->assertEqualsWithDelta(20, (float) $vermifugo->fresh()->quantidade, 0.01, 'vermifugo_novo_correto');

        // ── 4. Valor de estoque — resposta direta a LAB-FA-015 (ficava R$0,00 mesmo com produto físico real) ──
        $valorEstoque = Insumo::where('fazenda_id', $fazenda)->get()
            ->sum(fn (Insumo $i) => (float) $i->quantidade * (float) $i->valor_referencia);
        $valorEsperado = (175 * 18.00) + (10 * 220.00) + (20 * 32.00);
        $this->assertEqualsWithDelta($valorEsperado, $valorEstoque, 0.01, 'valor_de_estoque_computavel_nunca_zero');
        $this->assertGreaterThan(0, $valorEstoque, 'nunca_zero_com_produto_real_em_estoque');

        // ── 5. Persistência histórica — 2ª Compra não reescreve a 1ª ──
        $this->assertEqualsWithDelta($valorTotal1, (float) $resultado1['compra']->fresh()->valor_total, 0.01, 'primeira_compra_intocada_pela_segunda');
        $this->assertSame(2, CompraInsumoItem::where('compra_insumo_id', $resultado1['compra']->id)->count(), 'itens_da_primeira_compra_continuam_existindo');
        $this->assertSame(2, CompraInsumoItem::where('compra_insumo_id', $resultado2['compra']->id)->count(), 'itens_da_segunda_compra');

        // ── 6. Obrigações — cada Compra com a sua, nunca fundidas ──
        $this->assertSame(2, ObrigacaoFinanceira::where('fazenda_id', $fazenda)->count(), 'duas_obrigacoes_uma_por_compra');
        $obrigacao1 = ObrigacaoFinanceira::where('compra_insumo_id', $resultado1['compra']->id)->first();
        $obrigacao2 = ObrigacaoFinanceira::where('compra_insumo_id', $resultado2['compra']->id)->first();
        $this->assertEqualsWithDelta($valorTotal1, (float) $obrigacao1->valor, 0.01, 'obrigacao_1_nao_alterada_pela_segunda_compra');
        $valorTotal2 = 95 * 18.00 + 20 * 32.00;
        $this->assertEqualsWithDelta($valorTotal2, (float) $obrigacao2->valor, 0.01, 'obrigacao_2_correta');

        // ── 7. Eventos — os dois presentes, cada um com sua Fazenda ──
        $eventoCompra2 = EventoDominio::where('fazenda_id', $fazenda)->where('tipo', 'compra_insumo_concluida')->where('id', '!=', $eventoCompra1->id)->first();
        $this->assertNotNull(EventoDominio::find($eventoCompra1->id), 'evento_1_permanece');
        $this->assertNotNull($eventoCompra2, 'evento_2_produzido');
        $this->assertSame($fazenda, (int) $eventoCompra1->fresh()->fazenda_id);
        $this->assertSame($fazenda, (int) $eventoCompra2->fazenda_id);
    }
}
