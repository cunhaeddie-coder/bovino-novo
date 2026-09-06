<?php

namespace Tests\Feature\VerticalConsumoInsumo;

use App\Models\Fazenda;
use App\Models\Insumo;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ConsumoInsumoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** INV-029 — isolamento entre Fazendas, mesmo padrão dos 5 verticais anteriores. */
class IsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_de_outra_fazenda_nao_ve_o_consumo(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $insumo = Insumo::create(['fazenda_id' => $fazendaA, 'nome' => 'Sal', 'quantidade' => 100])->id;

        $service = app(ConsumoInsumoService::class);
        $resultado = $service->registrar($jose, $fazendaA, $insumo, 10, '2026-01-01 09:00:00', 'x');

        $this->assertNull($service->buscar($mariazinha, $resultado['consumo']->id));
        $this->assertNotNull($service->buscar($jose, $resultado['consumo']->id));
    }

    public function test_chaves_iguais_em_fazendas_diferentes_nao_sao_reenvio(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $insumoA = Insumo::create(['fazenda_id' => $fazendaA, 'nome' => 'Sal', 'quantidade' => 100])->id;
        $insumoB = Insumo::create(['fazenda_id' => $fazendaB, 'nome' => 'Sal', 'quantidade' => 100])->id;

        $service = app(ConsumoInsumoService::class);
        $resultadoA = $service->registrar($jose, $fazendaA, $insumoA, 10, '2026-01-01 09:00:00', 'chave-igual');
        $resultadoB = $service->registrar($mariazinha, $fazendaB, $insumoB, 20, '2026-01-01 09:00:00', 'chave-igual');

        $this->assertFalse($resultadoB['reenvio_detectado']);
        $this->assertNotSame($resultadoA['consumo']->id, $resultadoB['consumo']->id);
    }
}
