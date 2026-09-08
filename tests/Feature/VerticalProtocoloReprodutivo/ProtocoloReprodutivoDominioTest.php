<?php

namespace Tests\Feature\VerticalProtocoloReprodutivo;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ProtocoloReprodutivoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 13 (Protocolo Reprodutivo — IATF) — nasce de
 * VERTICAL-PROTOCOLO-REPRODUTIVO.md e SCHEMA-CONTRATO-PROTOCOLO-REPRODUTIVO.md.
 * Reproduz LAB-FA-021 (protocolo IATF em 50 vacas, 3 etapas — deveriam ser
 * um único ciclo reconhecível, não 3 EventoReproducao idênticos e soltos).
 */
class ProtocoloReprodutivoDominioTest extends TestCase
{
    use RefreshDatabase;

    private ProtocoloReprodutivoService $protocolos;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->protocolos = app(ProtocoloReprodutivoService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
    }

    private function criarAnimais(int $quantidade): array
    {
        return collect(range(1, $quantidade))
            ->map(fn () => Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id)
            ->all();
    }

    /** LAB-FA-021 — as 3 etapas do IATF nascem juntas, agendadas desde o início, mesmo ciclo reconhecível. */
    public function test_iniciar_cria_as_3_etapas_com_datas_previstas_corretas(): void
    {
        $animais = $this->criarAnimais(2);
        $registro = $this->protocolos->iniciar($this->jose, $this->fazenda, $animais, '2026-01-01 08:00:00', 'protocolo-1');

        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertSame('em_andamento', $registro['protocolo']->status);

        $etapas = $registro['protocolo']->etapas()->orderBy('data_prevista')->get();
        $this->assertCount(3, $etapas);

        $implante = $etapas->firstWhere('tipo', 'implante');
        $prostaglandina = $etapas->firstWhere('tipo', 'prostaglandina');
        $retiradaIa = $etapas->firstWhere('tipo', 'retirada_ia');

        $this->assertNotNull($implante->data_realizada, 'iniciar_ja_cumpre_a_1a_etapa');
        $this->assertSame('2026-01-08 08:00:00', $prostaglandina->data_prevista->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-10 08:00:00', $retiradaIa->data_prevista->format('Y-m-d H:i:s'));
        $this->assertNull($prostaglandina->data_realizada);
        $this->assertNull($retiradaIa->data_realizada);

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'protocolo_reprodutivo_iniciado')->first();
        $this->assertNotNull($evento);
    }

    public function test_cumprir_as_3_etapas_conclui_o_protocolo(): void
    {
        $animais = $this->criarAnimais(1);
        $registro = $this->protocolos->iniciar($this->jose, $this->fazenda, $animais, '2026-01-01 08:00:00', 'protocolo-2');
        $protocoloId = $registro['protocolo']->id;

        $r1 = $this->protocolos->cumprirEtapa($this->jose, $protocoloId, 'prostaglandina', '2026-01-08 08:00:00');
        $this->assertSame('em_andamento', $r1['protocolo']->status, 'ainda_falta_1_etapa');

        $r2 = $this->protocolos->cumprirEtapa($this->jose, $protocoloId, 'retirada_ia', '2026-01-10 08:00:00');
        $this->assertSame('concluido', $r2['protocolo']->status, 'as_3_etapas_cumpridas_conclui');

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'etapa_protocolo_cumprida')->count();
        $this->assertSame(2, $evento);
    }

    public function test_cumprir_etapa_ja_cumprida_e_idempotente(): void
    {
        $animais = $this->criarAnimais(1);
        $registro = $this->protocolos->iniciar($this->jose, $this->fazenda, $animais, '2026-01-01 08:00:00', 'protocolo-3');

        $primeira = $this->protocolos->cumprirEtapa($this->jose, $registro['protocolo']->id, 'prostaglandina', '2026-01-08 08:00:00');
        $segunda = $this->protocolos->cumprirEtapa($this->jose, $registro['protocolo']->id, 'prostaglandina', '2026-01-08 09:00:00');

        $this->assertFalse($primeira['reenvio_detectado']);
        $this->assertTrue($segunda['reenvio_detectado']);
        $this->assertSame(
            $primeira['etapa']->data_realizada->format('Y-m-d H:i:s'),
            $segunda['etapa']->data_realizada->format('Y-m-d H:i:s')
        );
    }

    public function test_reenvio_do_iniciar_e_idempotente(): void
    {
        $animais = $this->criarAnimais(1);
        $primeiro = $this->protocolos->iniciar($this->jose, $this->fazenda, $animais, '2026-01-01 08:00:00', 'protocolo-mesma-chave');
        $segundo = $this->protocolos->iniciar($this->jose, $this->fazenda, $animais, '2026-01-01 08:00:00', 'protocolo-mesma-chave');

        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['protocolo']->id, $segundo['protocolo']->id);
    }
}
