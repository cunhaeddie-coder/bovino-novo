<?php

namespace Tests\Feature\VerticalCompraInsumo;

use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Insumo;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\CompraInsumoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Reexecução da fatia real de REEXECUCAO-3-VERTICAIS.md que toca o Vertical
 * Compra de Insumo além de LAB-SA-003 (CicloIntegradoTest) e LAB-FA-015
 * (mesmo teste, conceito provado com outros números).
 *
 * Achado de fronteira encontrado ao escrever este teste, não escondido:
 * `obrigacoes_financeiras` não tem coluna de vencimento — `status` é sempre
 * 'pago', fixado por CompraInsumoService::registrar(), qualquer que seja o
 * prazo real combinado. Isso significa que a dimensão "à vista vs. a prazo"
 * de LAB-SA-005/LAB-FA-008 (ambos "a prazo 30 dias") não é representável
 * hoje — nem por decisão de domínio (parcelamento, essa sim decidida fora do
 * corte mínimo), só porque a coluna nunca existiu. Os testes abaixo verificam
 * só o que o vertical realmente promete (uma única obrigação financeira
 * correta, vinculando todos os itens) e não afirmam nada sobre prazo.
 */
class ReexecucaoCenariosOriginaisTest extends TestCase
{
    use RefreshDatabase;

    private CompraInsumoService $compras;

    private int $fazenda;

    private int $jose;

    private int $tiago;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compras = app(CompraInsumoService::class);

        $this->fazenda = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->tiago = Fornecedor::create(['nome' => 'Tiago (Saúde Animal)'])->id;
    }

    /**
     * LAB-SA-005: José compra vacina contra clostridioses (100 doses,
     * R$8,50) e vermífugo (20 frascos, R$32,00) do vendedor Tiago — total
     * R$1.490,00. Checar no Bovino Novo: a compra deve gerar uma única
     * dívida vinculando os dois itens (a metade "aplicar não deve duplicar
     * despesa" fica fora — consumo/baixa de estoque não é parte do corte
     * mínimo de Compra de Insumo, é candidato próprio registrado em
     * SONDAGEM-VERTICAL-2.md).
     */
    public function test_lab_sa_005_compra_de_vacina_e_vermifugo_gera_uma_unica_divida(): void
    {
        $resultado = $this->compras->registrar(
            $this->jose, $this->fazenda, $this->tiago,
            [
                ['insumo_novo' => ['nome' => 'Vacina Clostridioses'], 'quantidade' => 100, 'valor_unitario' => 8.50],
                ['insumo_novo' => ['nome' => 'Vermífugo'], 'quantidade' => 20, 'valor_unitario' => 32.00],
            ],
            '2026-02-01', 'lab-sa-005-compra-vacina-vermifugo'
        );

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertEqualsWithDelta(1490.00, (float) $resultado['compra']->fresh()->valor_total, 0.01, 'valor_total_1490_igual_ao_cenario_original');

        $this->assertSame(1, ObrigacaoFinanceira::where('compra_insumo_id', $resultado['compra']->id)->count(), 'uma_unica_divida_nunca_uma_por_item');
        $this->assertEqualsWithDelta(1490.00, (float) ObrigacaoFinanceira::where('compra_insumo_id', $resultado['compra']->id)->first()->valor, 0.01, 'divida_com_valor_correto');

        $vacina = Insumo::where('fazenda_id', $this->fazenda)->where('nome', 'Vacina Clostridioses')->first();
        $vermifugo = Insumo::where('fazenda_id', $this->fazenda)->where('nome', 'Vermífugo')->first();
        $this->assertEqualsWithDelta(100, (float) $vacina->quantidade, 0.01);
        $this->assertEqualsWithDelta(20, (float) $vermifugo->quantidade, 0.01);
    }

    /**
     * LAB-FA-008: vacinação em escala proporcional a LAB-SA-005 — ≈521 doses
     * de vacina, ≈104 frascos de vermífugo, ≈R$7.756,50. Mesma expectativa,
     * em escala maior — confirma que o núcleo não depende de ordem de
     * grandeza.
     */
    public function test_lab_fa_008_compra_de_insumo_em_escala_confirma_o_mesmo_nucleo(): void
    {
        $resultado = $this->compras->registrar(
            $this->jose, $this->fazenda, $this->tiago,
            [
                ['insumo_novo' => ['nome' => 'Vacina Clostridioses'], 'quantidade' => 521, 'valor_unitario' => 8.50],
                ['insumo_novo' => ['nome' => 'Vermífugo'], 'quantidade' => 104, 'valor_unitario' => 32.00],
            ],
            '2026-02-01', 'lab-fa-008-compra-vacina-vermifugo-escala'
        );

        $valorEsperado = round(521 * 8.50 + 104 * 32.00, 2);
        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertEqualsWithDelta($valorEsperado, (float) $resultado['compra']->fresh()->valor_total, 0.01, 'valor_total_correto_em_escala');
        $this->assertSame(1, ObrigacaoFinanceira::where('compra_insumo_id', $resultado['compra']->id)->count(), 'uma_unica_divida_tambem_em_escala');
    }

    /** Achado de fronteira registrado, não escondido — ver docblock da classe. */
    public function test_ainda_nao_existe_coluna_de_vencimento_a_prazo_nao_e_representavel(): void
    {
        $this->assertFalse(
            Schema::hasColumn('obrigacoes_financeiras', 'vencimento'),
            'confirma_o_gap_a_prazo_nao_modelado_nao_e_regressao_deste_vertical_e_sim_escopo_nunca_prometido'
        );
    }
}
