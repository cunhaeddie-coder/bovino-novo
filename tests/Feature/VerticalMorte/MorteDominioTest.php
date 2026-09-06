<?php

namespace Tests\Feature\VerticalMorte;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Lote;
use App\Models\Morte;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Models\Venda;
use App\Services\MorteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 7 (Morte) — nasce de VERTICAL-MORTE.md e
 * SCHEMA-CONTRATO-MORTE.md. Reproduz LAB-SA-007 (sem lote) e LAB-FA-002
 * (com lote, INV-001).
 */
class MorteDominioTest extends TestCase
{
    use RefreshDatabase;

    private MorteService $mortes;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mortes = app(MorteService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
    }

    /** LAB-SA-007 — bezerro morre, sem valor de aquisição capitalizado. 🟢 acerto no Atual, reproduzido aqui. */
    public function test_morte_sem_lote_da_baixa_limpa_sem_recalculo(): void
    {
        $bezerro = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo']);

        $resultado = $this->mortes->registrar($this->jose, $this->fazenda, [$bezerro->id], 'acidente', '2026-01-01 08:00:00', 'morte-1');

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertSame('morto', $bezerro->fresh()->status);
        $this->assertSame('2026-01-01', $bezerro->fresh()->data_saida->toDateString());
    }

    /** LAB-FA-002 — 10 vacas morrem com valor capitalizado. 🔴 erro no Atual (INV-001 violado) — corrigido aqui. */
    public function test_morte_com_lote_recalcula_proporcionalmente_inv001(): void
    {
        $lote = Lote::create(['fazenda_id' => $this->fazenda, 'qtd_animais' => 500, 'custo_aquisicao' => 524000.00]);
        $vacas = collect(range(1, 10))
            ->map(fn () => Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => $lote->id, 'status' => 'ativo'])->id)
            ->all();

        $this->mortes->registrar($this->jose, $this->fazenda, $vacas, 'doença', '2026-01-01 08:00:00', 'morte-fa-002');

        $loteDepois = $lote->fresh();
        $this->assertSame(490, $loteDepois->qtd_animais, 'baixa_de_10_vacas_do_lote');
        $custoEsperado = round(524000.00 * 490 / 500, 2);
        $this->assertEqualsWithDelta($custoEsperado, (float) $loteDepois->custo_aquisicao, 0.02, 'custo_recalculado_proporcionalmente_inv001');

        foreach ($vacas as $vacaId) {
            $this->assertSame('morto', Animal::find($vacaId)->status);
        }
    }

    public function test_morte_coletiva_e_1_evento_referenciando_n_animais(): void
    {
        $animais = collect(range(1, 5))
            ->map(fn () => Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id)
            ->all();

        $resultado = $this->mortes->registrar($this->jose, $this->fazenda, $animais, 'raio', '2026-01-01 08:00:00', 'morte-coletiva');

        $this->assertSame(1, Morte::where('fazenda_id', $this->fazenda)->count(), 'um_unico_evento');
        $this->assertSame($animais, $resultado['morte']->animal_ids);
    }

    public function test_morte_nunca_gera_obrigacao_financeira(): void
    {
        $animal = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo']);
        $this->mortes->registrar($this->jose, $this->fazenda, [$animal->id], 'abate', '2026-01-01 08:00:00', 'morte-sem-financeiro');

        $this->assertSame(0, ObrigacaoFinanceira::where('fazenda_id', $this->fazenda)->count());
        $this->assertSame(0, Venda::where('fazenda_id', $this->fazenda)->count());
    }

    public function test_evento_dominio_e_disparado(): void
    {
        $animal = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo']);
        $this->mortes->registrar($this->jose, $this->fazenda, [$animal->id], 'doença', '2026-01-01 08:00:00', 'morte-evento');

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'morte_registrada')->first();
        $this->assertNotNull($evento);
    }

    public function test_reenvio_da_mesma_chave_e_idempotente(): void
    {
        $animal = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo']);
        $primeiro = $this->mortes->registrar($this->jose, $this->fazenda, [$animal->id], 'doença', '2026-01-01 08:00:00', 'morte-x');
        $segundo = $this->mortes->registrar($this->jose, $this->fazenda, [$animal->id], 'doença', '2026-01-01 08:00:00', 'morte-x');

        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['morte']->id, $segundo['morte']->id);
    }
}
