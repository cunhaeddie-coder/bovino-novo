<?php

namespace Tests\Feature\VerticalCompra;

use App\Models\Animal;
use App\Models\Compra;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\CompraService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical Compra — nasce de VERTICAL-COMPRA.md e
 * SCHEMA-CONTRATO-COMPRA.md, não do código. Caso A reproduz LAB-SA-012
 * (compra de 1 touro, R$15.000, à vista) contra a implementação real.
 */
class CompraDominioTest extends TestCase
{
    use RefreshDatabase;

    private CompraService $compras;

    private int $fazenda;

    private int $jose;

    private int $marilia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compras = app(CompraService::class);

        $this->fazenda = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->marilia = Fornecedor::create(['nome' => 'Marília'])->id;
    }

    /** Caso A — LAB-SA-012: José compra 1 touro garrote, R$15.000,00, à vista. */
    public function test_caso_a_compra_individual_de_animal(): void
    {
        $resultado = $this->compras->registrar($this->jose, $this->fazenda, $this->marilia, [15000.00], '2026-02-26', 'compra-jose-touro-2026-02-26');

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertCount(1, $resultado['animais']);

        $animal = $resultado['animais'][0]->fresh();
        $this->assertSame('ativo', $animal->status, 'a1_animal_ativo');
        $this->assertNull($animal->lote_id, 'a2_sem_lote_compra_individual');
        $this->assertEqualsWithDelta(15000.00, (float) $animal->custo_aquisicao, 0.01, 'a3_custo_aquisicao_correto');

        $compra = $resultado['compra']->fresh();
        $this->assertEqualsWithDelta(15000.00, (float) $compra->valor_total, 0.01, 'a4_valor_total_correto');
        $this->assertSame($this->marilia, $compra->fornecedor_id, 'a5_fornecedor_correto');
        $this->assertSame('2026-02-26', $compra->data_compra->toDateString(), 'a6_data_real_nao_data_de_digitacao');

        $obrigacao = ObrigacaoFinanceira::where('compra_id', $compra->id)->first();
        $this->assertNotNull($obrigacao, 'a7_obrigacao_financeira_existe');
        $this->assertEqualsWithDelta(15000.00, (float) $obrigacao->valor, 0.01, 'a7_obrigacao_valor_correto');
        $this->assertSame('pago', $obrigacao->status, 'a8_pago_imediato_a_vista');
        $this->assertSame(1, ObrigacaoFinanceira::where('compra_id', $compra->id)->count(), 'a9_exatamente_uma_obrigacao_nunca_duplicada');

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'compra_concluida')->first();
        $this->assertNotNull($evento, 'a10_evento_dominio_existe');

        // Reenvio — mesma chave, nunca reprocessa.
        $reenvio = $this->compras->registrar($this->jose, $this->fazenda, $this->marilia, [15000.00], '2026-02-26', 'compra-jose-touro-2026-02-26');
        $this->assertTrue($reenvio['reenvio_detectado'], 'a11_reenvio_detectado');
        $this->assertSame($compra->id, $reenvio['compra']->id, 'a11_mesma_compra_retornada');
        $this->assertSame(1, Animal::where('fazenda_id', $this->fazenda)->count(), 'a12_nenhum_animal_duplicado_por_reenvio');
    }

    /** Múltiplos animais, preços diferentes — decisão do produtor (27/08/2026), inspirado em LAB-FA-024. */
    public function test_multiplos_animais_com_precos_individuais_diferentes(): void
    {
        $resultado = $this->compras->registrar($this->jose, $this->fazenda, $this->marilia, [5000.00, 6500.00, 7200.00], '2026-03-01', 'compra-lote-misto');

        $this->assertCount(3, $resultado['animais']);
        $custos = collect($resultado['animais'])->map(fn (Animal $a) => (float) $a->fresh()->custo_aquisicao)->sort()->values();

        $this->assertEqualsWithDelta(5000.00, $custos[0], 0.01, 'b1_preco_individual_1');
        $this->assertEqualsWithDelta(6500.00, $custos[1], 0.01, 'b2_preco_individual_2');
        $this->assertEqualsWithDelta(7200.00, $custos[2], 0.01, 'b3_preco_individual_3_nunca_dividido_igualmente');

        $valorTotalEsperado = 5000.00 + 6500.00 + 7200.00;
        $this->assertEqualsWithDelta($valorTotalEsperado, (float) $resultado['compra']->fresh()->valor_total, 0.01, 'b4_valor_total_e_soma');

        foreach ($resultado['animais'] as $animal) {
            $this->assertNull($animal->fresh()->lote_id, 'b5_nenhum_lote_automatico');
        }
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_registra_compra(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;

        $this->assertThrows(
            fn () => $this->compras->registrar($mariazinha, $this->fazenda, $this->marilia, [1000.00], '2026-01-01', 'ataque-mariazinha'),
            DomainException::class
        );
    }

    /** GATE-DECISAO-DOMINIO-DATA-HORA.md (04/09/2026) — data_compra precisa preservar hora, não só dia. */
    public function test_data_compra_preserva_hora_real_declarada(): void
    {
        $resultado = $this->compras->registrar($this->jose, $this->fazenda, $this->marilia, [1000.00], '2026-05-10 16:45:00', 'compra-com-hora');

        $this->assertSame('2026-05-10 16:45:00', $resultado['compra']->fresh()->data_compra->format('Y-m-d H:i:s'), 'hora_real_declarada_preservada_exata');
    }
}
