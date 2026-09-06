<?php

namespace Tests\Feature\VerticalConsumoInsumo;

use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Insumo;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ConsumoInsumoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 5 (Consumo de Insumo) — nasce de
 * VERTICAL-CONSUMO-INSUMO.md e SCHEMA-CONTRATO-CONSUMO-INSUMO.md.
 * Reproduz LAB-SA-005 (100 doses compradas, aplicadas em 150 cabeças).
 */
class ConsumoInsumoDominioTest extends TestCase
{
    use RefreshDatabase;

    private ConsumoInsumoService $consumos;

    private int $fazenda;

    private int $jose;

    private int $vacina;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumos = app(ConsumoInsumoService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->vacina = Insumo::create(['fazenda_id' => $this->fazenda, 'nome' => 'Vacina Aftosa', 'quantidade' => 100])->id;
    }

    /** LAB-SA-005 — 100 doses de vacina, aplicadas em 150 cabeças (2 doses cada = 100 doses no total real, cenário simplificado aqui a 1 consumo). */
    public function test_consumo_da_baixa_real_no_estoque_inv004(): void
    {
        $resultado = $this->consumos->registrar($this->jose, $this->fazenda, $this->vacina, 100.00, '2026-01-01 09:00:00', 'consumo-vacina-lote-1');

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertEqualsWithDelta(0.00, (float) $resultado['insumo']->quantidade, 0.01, 'estoque_zera_apos_consumo_total');
        $this->assertSame('2026-01-01 09:00:00', $resultado['consumo']->data_consumo->format('Y-m-d H:i:s'));

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'consumo_insumo_registrado')->first();
        $this->assertNotNull($evento, 'evento_dominio_existe');
    }

    public function test_consumo_parcial_reduz_proporcionalmente(): void
    {
        $resultado = $this->consumos->registrar($this->jose, $this->fazenda, $this->vacina, 40.00, '2026-01-01 09:00:00', 'consumo-parcial');

        $this->assertEqualsWithDelta(60.00, (float) $resultado['insumo']->quantidade, 0.01);
    }

    public function test_reenvio_da_mesma_chave_e_idempotente(): void
    {
        $primeiro = $this->consumos->registrar($this->jose, $this->fazenda, $this->vacina, 40.00, '2026-01-01 09:00:00', 'consumo-x');
        $segundo = $this->consumos->registrar($this->jose, $this->fazenda, $this->vacina, 40.00, '2026-01-01 09:00:00', 'consumo-x');

        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['consumo']->id, $segundo['consumo']->id);

        // Não processou duas vezes — estoque só baixou uma vez.
        $this->assertEqualsWithDelta(60.00, (float) Insumo::find($this->vacina)->quantidade, 0.01, 'nao_processou_duas_vezes');
    }

    public function test_nenhuma_obrigacao_financeira_e_criada_consumo_nunca_e_evento_financeiro(): void
    {
        $this->consumos->registrar($this->jose, $this->fazenda, $this->vacina, 40.00, '2026-01-01 09:00:00', 'consumo-sem-financeiro');

        $this->assertSame(0, ObrigacaoFinanceira::where('fazenda_id', $this->fazenda)->count(), 'consumo_nao_gera_obrigacao_financeira');
    }
}
