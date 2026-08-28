<?php

namespace Tests\Feature\VerticalCompra;

use App\Models\Animal;
use App\Models\CompraItem;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\CompraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Reexecução da fatia real de REEXECUCAO-3-VERTICAIS.md que toca o Vertical
 * Compra (Animal) além de LAB-SA-012 (já coberto por CompraDominioTest).
 */
class ReexecucaoCenariosOriginaisTest extends TestCase
{
    use RefreshDatabase;

    /**
     * LAB-SA-018: José compra 40 vacas leiteiras já em produção, R$150.000,00
     * (R$3.750,00/cabeça), de um terceiro. Checar no Bovino Novo: o cadastro
     * em lote (aqui, Compra de múltiplos animais) deve gerar uma única
     * obrigação financeira, e o valor por animal deve ser preservado.
     *
     * O achado original era sobre um atributo especializado (status
     * leiteiro/em lactação) não representável em lote — esse atributo não
     * existe em nenhum caminho de bovino-novo ainda, fora do corte mínimo do
     * Vertical Compra (nunca prometido por ele). Este teste cobre só a parte
     * financeira/estrutural que o vertical realmente promete, sem fingir que
     * o status leiteiro está resolvido.
     */
    public function test_lab_sa_018_compra_em_lote_de_40_vacas_leiteiras_financeiro_correto(): void
    {
        $compras = app(CompraService::class);

        $fazenda = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazenda, 'papel' => 'dono']);
        $terceiro = Fornecedor::create(['nome' => 'Terceiro'])->id;

        $valoresPorAnimal = array_fill(0, 40, 3750.00);
        $resultado = $compras->registrar($jose, $fazenda, $terceiro, $valoresPorAnimal, '2026-04-10', 'lab-sa-018-compra-lote-leiteiras');

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertCount(40, $resultado['animais'], 'quarenta_animais_criados');
        $this->assertEqualsWithDelta(150000.00, (float) $resultado['compra']->fresh()->valor_total, 0.01, 'valor_total_150000');
        $this->assertSame(40, CompraItem::where('compra_id', $resultado['compra']->id)->count(), 'quarenta_itens_de_compra');

        foreach ($resultado['animais'] as $animal) {
            $this->assertEqualsWithDelta(3750.00, (float) $animal->fresh()->custo_aquisicao, 0.01, 'custo_individual_correto');
            $this->assertNull($animal->fresh()->lote_id, 'lote_nunca_automatico');
        }

        $this->assertSame(1, ObrigacaoFinanceira::where('compra_id', $resultado['compra']->id)->count(), 'uma_unica_obrigacao_nunca_quarenta');
        $this->assertEqualsWithDelta(150000.00, (float) ObrigacaoFinanceira::where('compra_id', $resultado['compra']->id)->first()->valor, 0.01, 'obrigacao_com_valor_total_correto');

        // Ainda não coberto por decisão de escopo, registrado sem suavizar:
        // status leiteiro/em lactação não é um campo de Animal em bovino-novo.
        $this->assertFalse(
            Schema::hasColumn((new Animal)->getTable(), 'status_leiteiro'),
            'status_leiteiro_confirmado_fora_do_corte_minimo_nao_e_regressao_do_vertical'
        );
    }
}
