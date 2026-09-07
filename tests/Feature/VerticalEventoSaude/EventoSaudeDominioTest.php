<?php

namespace Tests\Feature\VerticalEventoSaude;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Insumo;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\EventoSaudeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 11 (Evento de Saúde) — nasce de
 * VERTICAL-EVENTO-SAUDE.md e SCHEMA-CONTRATO-EVENTO-SAUDE.md. Reproduz
 * LAB-SA-005 (100 doses de vacina aplicadas em 150 cabeças — estoque nunca
 * baixava, e relançar o custo duplicaria a despesa já paga na Compra).
 */
class EventoSaudeDominioTest extends TestCase
{
    use RefreshDatabase;

    private EventoSaudeService $eventos;

    private int $fazenda;

    private int $jose;

    private int $insumo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventos = app(EventoSaudeService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->insumo = Insumo::create(['fazenda_id' => $this->fazenda, 'nome' => 'Vacina Aftosa', 'quantidade' => 100])->id;
    }

    private function criarAnimais(int $quantidade): array
    {
        return collect(range(1, $quantidade))
            ->map(fn () => Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id)
            ->all();
    }

    /** LAB-SA-005 — vacinação em lote finalmente dá baixa real no estoque. */
    public function test_registro_de_evento_de_saude_da_baixa_real_no_estoque(): void
    {
        $animais = $this->criarAnimais(3);
        $registro = $this->eventos->registrar($this->jose, $this->fazenda, $animais, $this->insumo, 30.0, 'vacina aftosa', '2026-01-01 08:00:00', 'evento-1');

        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertSame($animais, $registro['evento']->animal_ids);

        $this->assertEqualsWithDelta(70.0, (float) Insumo::find($this->insumo)->quantidade, 0.01);

        $consumo = $registro['evento']->consumoInsumo;
        $this->assertNotNull($consumo);
        $this->assertEqualsWithDelta(30.0, (float) $consumo->quantidade, 0.01);

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'evento_saude_registrado')->first();
        $this->assertNotNull($evento);
    }

    /** INV-005 — nenhuma despesa duplicada: o custo já foi pago na Compra do insumo. */
    public function test_evento_de_saude_nunca_gera_obrigacao_financeira(): void
    {
        $animais = $this->criarAnimais(1);
        $this->eventos->registrar($this->jose, $this->fazenda, $animais, $this->insumo, 5.0, 'vermifugo', '2026-01-01 08:00:00', 'evento-2');

        $this->assertSame(0, ObrigacaoFinanceira::where('fazenda_id', $this->fazenda)->count());
    }

    public function test_reenvio_do_registro_e_idempotente_e_nao_consome_estoque_duas_vezes(): void
    {
        $animais = $this->criarAnimais(2);
        $primeiro = $this->eventos->registrar($this->jose, $this->fazenda, $animais, $this->insumo, 10.0, 'vacina', '2026-01-01 08:00:00', 'evento-mesma-chave');
        $segundo = $this->eventos->registrar($this->jose, $this->fazenda, $animais, $this->insumo, 10.0, 'vacina', '2026-01-01 08:00:00', 'evento-mesma-chave');

        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['evento']->id, $segundo['evento']->id);
        $this->assertEqualsWithDelta(90.0, (float) Insumo::find($this->insumo)->quantidade, 0.01, 'estoque_baixou_uma_unica_vez');
    }
}
