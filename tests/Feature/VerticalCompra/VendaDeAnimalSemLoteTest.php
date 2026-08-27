<?php

namespace Tests\Feature\VerticalCompra;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Lote;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\CompraService;
use App\Services\VendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VERTICAL-COMPRA.md §9 — o ajuste sequenciado em VendaService, agora que
 * Compra existe de verdade. Ciclo completo: Compra (sem Lote) → Animal →
 * Venda → CPV. Nasce do fato real (LAB-SA-012 comprou; o animal precisa
 * poder ser vendido depois sem mentir ou quebrar).
 */
class VendaDeAnimalSemLoteTest extends TestCase
{
    use RefreshDatabase;

    private CompraService $compras;

    private VendaService $vendas;

    private int $fazenda;

    private int $jose;

    private int $marilia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compras = app(CompraService::class);
        $this->vendas = app(VendaService::class);

        $this->fazenda = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->marilia = Fornecedor::create(['nome' => 'Marília'])->id;
    }

    public function test_ciclo_completo_compra_sem_lote_ate_venda_com_cpv_correto(): void
    {
        // José compra o touro (LAB-SA-012) — sem Lote, custo_aquisicao próprio.
        $compra = $this->compras->registrar($this->jose, $this->fazenda, $this->marilia, [15000.00], '2026-02-26', 'compra-touro');
        $touro = $compra['animais'][0];
        $this->assertNull($touro->fresh()->lote_id);

        // Um ano depois, José vende o touro por R$22.000,00.
        $venda = $this->vendas->registrar($this->jose, $this->fazenda, [$touro->id], 22000.00, 'venda-touro-2027');

        $this->assertEqualsWithDelta(15000.00, $venda['cpv'], 0.01, 'cpv_e_o_custo_de_aquisicao_direto_do_animal_nunca_dividido_de_lote_inexistente');
        $this->assertEqualsWithDelta(22000.00 - 15000.00, $venda['receita_liquida'], 0.01, 'receita_liquida_correta_sem_fallback_para_zero_ou_valor_inventado');
        $this->assertSame('vendido', $touro->fresh()->status);
    }

    public function test_venda_mista_animal_com_lote_e_sem_lote_na_mesma_transacao(): void
    {
        // Um animal com Lote (mesmo mecanismo já provado do Vertical Venda).
        $lote = Lote::create(['fazenda_id' => $this->fazenda, 'qtd_animais' => 2, 'custo_aquisicao' => 2000.00]);
        $animalComLote = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => $lote->id, 'status' => 'ativo']);

        // Um animal sem Lote (Compra individual).
        $compra = $this->compras->registrar($this->jose, $this->fazenda, $this->marilia, [3000.00], '2026-02-26', 'compra-individual');
        $animalSemLote = $compra['animais'][0];

        $venda = $this->vendas->registrar($this->jose, $this->fazenda, [$animalComLote->id, $animalSemLote->id], 10000.00, 'venda-mista');

        // CPV esperado: 1000.00 (metade do lote de 2000/2 animais) + 3000.00 (direto do animal sem lote).
        $this->assertEqualsWithDelta(4000.00, $venda['cpv'], 0.01, 'cpv_soma_os_dois_caminhos_corretamente');

        $loteDepois = $lote->fresh();
        $this->assertEqualsWithDelta(1, $loteDepois->qtd_animais, 0.01, 'lote_reduzido_só_pelo_animal_que_realmente_pertencia_a_ele');
        $this->assertEqualsWithDelta(1000.00, $loteDepois->custo_aquisicao, 0.01, 'custo_do_lote_reduzido_só_pela_parte_do_lote');
    }
}
