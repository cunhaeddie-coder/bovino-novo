<?php

namespace Tests\Feature\VerticalIncendio;

use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Incendio;
use App\Models\Papel;
use App\Models\Piquete;
use App\Models\Usuario;
use App\Services\IncendioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 20 (Incêndio) — nasce de
 * VERTICAL-INCENDIO.md e SCHEMA-CONTRATO-INCENDIO.md. Fato de domínio
 * confirmado pelo produtor (PERGUNTAS-DOMINIO.md, pergunta 54), sem
 * LAB-FA/LAB-SA reproduzindo o cenário no Atual.
 */
class IncendioDominioTest extends TestCase
{
    use RefreshDatabase;

    private IncendioService $incendios;

    private int $fazenda;

    private int $jose;

    private int $piquete;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incendios = app(IncendioService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->piquete = Piquete::create(['fazenda_id' => $this->fazenda, 'nome' => 'Piquete 1', 'dias_descanso' => 20])->id;
    }

    public function test_registro_grava_o_incendio_do_piquete(): void
    {
        $registro = $this->incendios->registrar($this->jose, $this->fazenda, $this->piquete, '2026-01-01 14:00:00', 'incendio-1');

        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertSame($this->piquete, $registro['incendio']->piquete_id);

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'incendio_registrado')->first();
        $this->assertNotNull($evento);
    }

    public function test_reenvio_do_registro_e_idempotente(): void
    {
        $primeiro = $this->incendios->registrar($this->jose, $this->fazenda, $this->piquete, '2026-01-01 14:00:00', 'mesma-chave');
        $segundo = $this->incendios->registrar($this->jose, $this->fazenda, $this->piquete, '2026-01-01 14:00:00', 'mesma-chave');

        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['incendio']->id, $segundo['incendio']->id);
        $this->assertSame(1, Incendio::where('fazenda_id', $this->fazenda)->count());
    }

    /** Reincidência real — segundo incêndio no mesmo Piquete, meses depois. */
    public function test_mesmo_piquete_pode_registrar_um_segundo_incendio(): void
    {
        $this->incendios->registrar($this->jose, $this->fazenda, $this->piquete, '2026-01-01 14:00:00', 'incendio-1');
        $segundo = $this->incendios->registrar($this->jose, $this->fazenda, $this->piquete, '2026-06-01 14:00:00', 'incendio-2');

        $this->assertFalse($segundo['reenvio_detectado']);
        $this->assertSame(2, Incendio::where('piquete_id', $this->piquete)->count());
    }
}
