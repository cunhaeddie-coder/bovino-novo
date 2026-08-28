<?php

namespace Tests\Feature\VerticalCompraInsumo;

use App\Models\CompraInsumo;
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
 * Idempotência sequencial de CompraInsumoService::registrar() — a forma
 * exata do contrato, antes de qualquer concorrência real. Lógica de
 * domínio, não depende de mecanismo de banco (Princípio 4b não se aplica
 * aqui) — SQLite é ambiente adequado pra esta pergunta especificamente.
 *
 * Não basta provar que o reenvio não duplica — precisa provar que a
 * PRIMEIRA execução já deixou o estado completo (CompraInsumo + itens +
 * Insumos com quantidade/valor_referencia corretos + obrigação + evento).
 */
class CompraInsumoIdempotenciaSequencialTest extends TestCase
{
    use RefreshDatabase;

    private CompraInsumoService $compras;

    private int $fazenda;

    private int $jose;

    private int $marilia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compras = app(CompraInsumoService::class);

        $this->fazenda = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->marilia = Fornecedor::create(['nome' => 'Marília'])->id;
    }

    public function test_primeira_execucao_deixa_estado_completo(): void
    {
        $r1 = $this->compras->registrar(
            $this->jose, $this->fazenda, $this->marilia,
            [
                ['insumo_novo' => ['nome' => 'Sal Branco 25kg'], 'quantidade' => 80, 'valor_unitario' => 17.99],
                ['insumo_novo' => ['nome' => 'Fosbovi Advance 25kg'], 'quantidade' => 10, 'valor_unitario' => 220.00],
            ],
            '2026-03-01', 'compra-insumo-x'
        );

        $this->assertFalse($r1['reenvio_detectado']);

        $compra = $r1['compra'];
        $valorEsperado = 80 * 17.99 + 10 * 220.00;
        $this->assertNotNull($compra->id, 'compra_existe');
        $this->assertEqualsWithDelta($valorEsperado, (float) $compra->fresh()->valor_total, 0.01, 'valor_total_completo');

        $this->assertSame(2, CompraInsumoItem::where('compra_insumo_id', $compra->id)->count(), 'dois_itens_completos');
        $this->assertSame(2, Insumo::where('fazenda_id', $this->fazenda)->count(), 'dois_insumos_completos');
        $this->assertSame(1, ObrigacaoFinanceira::where('compra_insumo_id', $compra->id)->count(), 'uma_obrigacao_completa');
        $this->assertEqualsWithDelta($valorEsperado, (float) ObrigacaoFinanceira::where('compra_insumo_id', $compra->id)->first()->valor, 0.01, 'obrigacao_valor_completo');
        $this->assertSame(1, EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'compra_insumo_concluida')->count(), 'um_evento_completo');

        // Nenhum insumo criado sem quantidade/valor_referencia — sinal de gravação parcial escondida atrás de uma resposta aparentemente boa.
        foreach (Insumo::where('fazenda_id', $this->fazenda)->get() as $insumo) {
            $this->assertGreaterThan(0, (float) $insumo->quantidade, 'nenhum_insumo_com_quantidade_zero');
            $this->assertGreaterThan(0, (float) $insumo->valor_referencia, 'nenhum_insumo_com_valor_referencia_zero');
        }
    }

    public function test_reenvio_sequencial_nao_duplica_nada(): void
    {
        $r1 = $this->compras->registrar(
            $this->jose, $this->fazenda, $this->marilia,
            [['insumo_novo' => ['nome' => 'Sal Branco 25kg'], 'quantidade' => 80, 'valor_unitario' => 17.99]],
            '2026-03-01', 'compra-insumo-x'
        );
        $r2 = $this->compras->registrar(
            $this->jose, $this->fazenda, $this->marilia,
            [['insumo_novo' => ['nome' => 'Sal Branco 25kg'], 'quantidade' => 80, 'valor_unitario' => 17.99]],
            '2026-03-01', 'compra-insumo-x'
        );
        $r3 = $this->compras->registrar(
            $this->jose, $this->fazenda, $this->marilia,
            [['insumo_novo' => ['nome' => 'Sal Branco 25kg'], 'quantidade' => 80, 'valor_unitario' => 17.99]],
            '2026-03-01', 'compra-insumo-x'
        );

        $this->assertFalse($r1['reenvio_detectado']);
        $this->assertTrue($r2['reenvio_detectado'], 'segunda_chamada_reenvio');
        $this->assertTrue($r3['reenvio_detectado'], 'terceira_chamada_reenvio');
        $this->assertSame($r1['compra']->id, $r2['compra']->id);
        $this->assertSame($r1['compra']->id, $r3['compra']->id);

        $this->assertSame(1, CompraInsumo::where('fazenda_id', $this->fazenda)->count(), 'uma_unica_compra_apos_3_tentativas');
        $this->assertSame(1, Insumo::where('fazenda_id', $this->fazenda)->count(), 'insumo_nunca_duplicado');
        $this->assertSame(1, CompraInsumoItem::count(), 'itens_nunca_duplicados');
        $this->assertSame(1, ObrigacaoFinanceira::where('compra_insumo_id', $r1['compra']->id)->count(), 'obrigacao_nunca_duplicada');
        $this->assertSame(1, EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'compra_insumo_concluida')->count(), 'evento_nunca_duplicado');

        // O ponto que Compra de Animal não tinha: reenvio não pode incrementar
        // quantidade de novo — 80, nunca 160 ou 240 depois de 3 tentativas.
        $insumo = Insumo::where('fazenda_id', $this->fazenda)->first();
        $this->assertEqualsWithDelta(80, (float) $insumo->fresh()->quantidade, 0.01, 'quantidade_intocada_apos_reenvios');
        $this->assertEqualsWithDelta(80 * 17.99, (float) $r1['compra']->fresh()->valor_total, 0.01, 'valor_total_intocado_apos_reenvios');
    }
}
