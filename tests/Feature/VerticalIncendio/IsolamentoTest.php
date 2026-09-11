<?php

namespace Tests\Feature\VerticalIncendio;

use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Piquete;
use App\Models\Usuario;
use App\Services\IncendioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** INV-029 — isolamento entre Fazendas, mesmo padrão dos 19 verticais anteriores. */
class IsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_de_outra_fazenda_nao_ve_o_incendio(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $piquete = Piquete::create(['fazenda_id' => $fazendaA, 'nome' => 'Piquete 1', 'dias_descanso' => 20])->id;

        $service = app(IncendioService::class);
        $registro = $service->registrar($jose, $fazendaA, $piquete, '2026-01-01 14:00:00', 'incendio-1');

        $this->assertNull($service->buscar($mariazinha, $registro['incendio']->id));
        $this->assertNotNull($service->buscar($jose, $registro['incendio']->id));
    }

    public function test_chaves_iguais_em_fazendas_diferentes_nao_sao_reenvio(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $piqueteA = Piquete::create(['fazenda_id' => $fazendaA, 'nome' => 'Piquete A', 'dias_descanso' => 20])->id;
        $piqueteB = Piquete::create(['fazenda_id' => $fazendaB, 'nome' => 'Piquete B', 'dias_descanso' => 20])->id;

        $service = app(IncendioService::class);
        $resultadoA = $service->registrar($jose, $fazendaA, $piqueteA, '2026-01-01 14:00:00', 'chave-igual');
        $resultadoB = $service->registrar($mariazinha, $fazendaB, $piqueteB, '2026-01-01 14:00:00', 'chave-igual');

        $this->assertFalse($resultadoB['reenvio_detectado']);
        $this->assertNotSame($resultadoA['incendio']->id, $resultadoB['incendio']->id);
    }
}
