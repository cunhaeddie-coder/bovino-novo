<?php

namespace Tests\Feature\VerticalVenda;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\CompraService;
use App\Services\VendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reexecução da fatia real de REEXECUCAO-3-VERTICAIS.md que toca o Vertical
 * Venda — LAB-SA-002 já está coberto por Spike006ReexecucaoTest::executarCasoA
 * (mesmos números: lote de 150 a R$524.000, venda de 28 por R$99.999,76), não
 * repetido aqui.
 *
 * Refaz o FATO narrado em cada cenário original com os números reais, não os
 * testes Pest/rota antigos — mesma regra de uso de CENARIOS-ORIGINAIS-PARA-REEXECUCAO.md.
 */
class ReexecucaoCenariosOriginaisTest extends TestCase
{
    use RefreshDatabase;

    private VendaService $vendas;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendas = app(VendaService::class);

        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
    }

    /**
     * LAB-FA-004: um touro comprado por R$12.500,00 é vendido por R$7.000,00.
     * Checar no Bovino Novo: a venda deve reconhecer o custo de aquisição —
     * o resultado real é PREJUÍZO de R$5.500,00, nunca lucro de R$7.000,00.
     */
    public function test_lab_fa_004_venda_de_animal_individual_reconhece_prejuizo(): void
    {
        $compras = app(CompraService::class);
        $fornecedor = Fornecedor::create(['nome' => 'Terceiro'])->id;
        $compra = $compras->registrar($this->jose, $this->fazenda, $fornecedor, [12500.00], '2025-01-01', 'lab-fa-004-compra');
        $touro = $compra['animais'][0];

        $venda = $this->vendas->registrar($this->jose, $this->fazenda, [$touro->id], 7000.00, '2025-01-02 09:00:00', 'lab-fa-004-venda');

        $this->assertEqualsWithDelta(12500.00, $venda['cpv'], 0.01, 'cpv_e_o_custo_de_aquisicao_real_nunca_ignorado');
        $this->assertEqualsWithDelta(7000.00 - 12500.00, $venda['receita_liquida'], 0.01, 'receita_liquida_negativa_prejuizo_reconhecido_nao_lucro_de_7000');
        $this->assertLessThan(0, $venda['receita_liquida'], 'prejuizo_nunca_apresentado_como_lucro');
    }

    /**
     * LAB-FA-014 + metade financeira de LAB-FA-018: José separa um lote de
     * 40 bezerros nascidos na fazenda (sem valor de aquisição capitalizado —
     * mesmo princípio já provado em LAB-SA-007) para "venda direta",
     * R$2.800,00/cabeça, R$112.000,00 total. Checar no Bovino Novo: toda
     * venda, por qualquer canal/finalidade, deve capturar o valor e gerar a
     * receita correspondente — não deve existir um caminho de "vender" que
     * zere o valor da venda (o achado original: a via com o nome mais óbvio
     * pra essa tarefa não registrava nenhum valor).
     *
     * LAB-FA-018 pede também a emissão do GTA — documento fiscal/legal fora
     * do corte mínimo (Fiscal não implementado). Este teste cobre só a
     * expectativa financeira que os dois cenários compartilham: concluir a
     * venda gera a mesma consequência financeira de qualquer outra venda.
     */
    public function test_lab_fa_014_e_lab_fa_018_venda_direta_de_lote_homogeneo_gera_receita_real(): void
    {
        $bezerros = collect(range(1, 40))->map(
            fn () => Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])
        );

        $venda = $this->vendas->registrar($this->jose, $this->fazenda, $bezerros->pluck('id')->all(), 112000.00, '2025-02-01 09:00:00', 'lab-fa-014-venda-direta');

        $this->assertFalse($venda['reenvio_detectado']);
        $this->assertEqualsWithDelta(0.0, $venda['cpv'], 0.01, 'nascidos_na_fazenda_sem_valor_capitalizado_cpv_zero');
        $this->assertEqualsWithDelta(112000.00, $venda['receita_liquida'] + $venda['deducao_fiscal'], 0.01, 'receita_bruta_registrada_nunca_zerada');
        $this->assertGreaterThan(0, $venda['receita_liquida'], 'via_de_venda_direta_registra_valor_real_nao_mais_zero');

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'venda_concluida')->first();
        $this->assertNotNull($evento, 'consequencia_financeira_produzida_para_qualquer_venda_concluida');

        $this->assertSame(40, Animal::where('fazenda_id', $this->fazenda)->where('status', 'vendido')->count(), 'os_40_animais_saem_do_rebanho_ativo');
    }
}
