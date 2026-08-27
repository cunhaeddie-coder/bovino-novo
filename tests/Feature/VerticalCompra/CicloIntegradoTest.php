<?php

namespace Tests\Feature\VerticalCompra;

use App\Models\CompraItem;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\CompraService;
use App\Services\VendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cenário integrado: Compra → múltiplos Animais → Venda parcial → CPV →
 * estado final. Não prova "Compra funciona" nem "Venda funciona" — cada uma
 * já provada isoladamente. Prova uma propriedade diferente: as garantias já
 * provadas isoladamente continuam verdadeiras quando o domínio é exercitado
 * como sequência contínua, não pedaços isolados.
 *
 * Deliberadamente sem concorrência aqui — já provada em laboratório próprio
 * (MySQL real, 19/19, 4 reproduções). Misturar diminuiria a capacidade de
 * saber qual propriedade falhou caso um problema apareça.
 */
class CicloIntegradoTest extends TestCase
{
    use RefreshDatabase;

    public function test_compra_multiplos_animais_venda_parcial_cpv_e_estado_final(): void
    {
        $compras = app(CompraService::class);
        $vendas = app(VendaService::class);

        $fazenda = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazenda, 'papel' => 'dono']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília'])->id;

        // ── 1. Compra de múltiplos animais, preços diferentes ──
        $resultadoCompra = $compras->registrar($jose, $fazenda, $fornecedor, [5000.00, 7500.00, 9000.00], '2026-01-10', 'compra-abc');

        $this->assertFalse($resultadoCompra['reenvio_detectado']);
        $this->assertCount(3, $resultadoCompra['animais']);
        [$animalA, $animalB, $animalC] = $resultadoCompra['animais'];

        foreach ($resultadoCompra['animais'] as $animal) {
            $this->assertNull($animal->fresh()->lote_id, 'lote_id_nulo');
        }
        $this->assertEqualsWithDelta(5000.00, (float) $animalA->fresh()->custo_aquisicao, 0.01);
        $this->assertEqualsWithDelta(7500.00, (float) $animalB->fresh()->custo_aquisicao, 0.01);
        $this->assertEqualsWithDelta(9000.00, (float) $animalC->fresh()->custo_aquisicao, 0.01);

        $compra = $resultadoCompra['compra'];
        $this->assertEqualsWithDelta(21500.00, (float) $compra->fresh()->valor_total, 0.01, 'compra_completa');
        $this->assertSame(3, CompraItem::where('compra_id', $compra->id)->count(), 'itens_completos');
        $obrigacaoOriginal = ObrigacaoFinanceira::where('compra_id', $compra->id)->first();
        $this->assertNotNull($obrigacaoOriginal, 'obrigacao_completa');
        $this->assertEqualsWithDelta(21500.00, (float) $obrigacaoOriginal->valor, 0.01);
        $eventoCompra = EventoDominio::where('fazenda_id', $fazenda)->where('tipo', 'compra_concluida')->first();
        $this->assertNotNull($eventoCompra, 'evento_compra_completo');

        // ── 2. Venda posterior de apenas alguns animais (A e C, não B) ──
        $resultadoVenda = $vendas->registrar($jose, $fazenda, [$animalA->id, $animalC->id], 20000.00, 'venda-a-e-c');

        $this->assertFalse($resultadoVenda['reenvio_detectado']);

        // Animais vendidos mudam, o não vendido permanece intocado.
        $this->assertSame('vendido', $animalA->fresh()->status, 'a_vendido');
        $this->assertSame('vendido', $animalC->fresh()->status, 'c_vendido');
        $this->assertSame('ativo', $animalB->fresh()->status, 'b_permanece_ativo_nenhum_baixado_por_engano');
        $this->assertEqualsWithDelta(7500.00, (float) $animalB->fresh()->custo_aquisicao, 0.01, 'b_custo_intocado');

        // ── 3. CPV: soma dos custos individuais dos animais vendidos, nunca dividido ──
        $this->assertEqualsWithDelta(5000.00 + 9000.00, $resultadoVenda['cpv'], 0.01, 'cpv_soma_direta_sem_lote');
        $this->assertEqualsWithDelta(20000.00 - 14000.00, $resultadoVenda['receita_liquida'], 0.01, 'receita_liquida_correta');

        // ── 4. Persistência histórica — Venda não reescreve o passado da Compra ──
        $this->assertEqualsWithDelta(21500.00, (float) $compra->fresh()->valor_total, 0.01, 'compra_original_intocada_pela_venda');
        $this->assertSame(3, CompraItem::where('compra_id', $compra->id)->count(), 'itens_da_compra_continuam_existindo');
        $this->assertSame($fazenda, $animalA->fresh()->fazenda_id, 'animal_continua_apontando_pra_sua_origem');

        // ── 5. Obrigação financeira — continua vinculada só à Compra, Venda não a altera ──
        $obrigacaoDepois = ObrigacaoFinanceira::where('compra_id', $compra->id)->first();
        $this->assertEqualsWithDelta(21500.00, (float) $obrigacaoDepois->valor, 0.01, 'obrigacao_da_compra_nao_alterada_pela_venda');
        $this->assertSame(1, ObrigacaoFinanceira::where('fazenda_id', $fazenda)->count(), 'venda_nao_cria_obrigacao_financeira_nenhuma');

        // ── 6. Eventos — compra_concluida permanece, venda_concluida é produzido, cada um com sua Fazenda ──
        $this->assertNotNull(EventoDominio::find($eventoCompra->id), 'evento_compra_permanece');
        $eventoVenda = EventoDominio::where('fazenda_id', $fazenda)->where('tipo', 'venda_concluida')->first();
        $this->assertNotNull($eventoVenda, 'evento_venda_produzido');
        $this->assertSame($fazenda, (int) $eventoCompra->fresh()->fazenda_id);
        $this->assertSame($fazenda, (int) $eventoVenda->fazenda_id);
    }
}
