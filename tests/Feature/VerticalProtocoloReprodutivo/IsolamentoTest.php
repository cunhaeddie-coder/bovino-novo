<?php

namespace Tests\Feature\VerticalProtocoloReprodutivo;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ProtocoloReprodutivoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** INV-029 — isolamento entre Fazendas, mesmo padrão dos 12 verticais anteriores. */
class IsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_de_outra_fazenda_nao_ve_o_protocolo(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $animal = Animal::create(['fazenda_id' => $fazendaA, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id;

        $service = app(ProtocoloReprodutivoService::class);
        $registro = $service->iniciar($jose, $fazendaA, [$animal], '2026-01-01 08:00:00', 'protocolo-1');

        $this->assertNull($service->buscar($mariazinha, $registro['protocolo']->id));
        $this->assertNotNull($service->buscar($jose, $registro['protocolo']->id));
    }

    public function test_chaves_iguais_em_fazendas_diferentes_nao_sao_reenvio(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $animalA = Animal::create(['fazenda_id' => $fazendaA, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id;
        $animalB = Animal::create(['fazenda_id' => $fazendaB, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id;

        $service = app(ProtocoloReprodutivoService::class);
        $resultadoA = $service->iniciar($jose, $fazendaA, [$animalA], '2026-01-01 08:00:00', 'chave-igual');
        $resultadoB = $service->iniciar($mariazinha, $fazendaB, [$animalB], '2026-01-01 08:00:00', 'chave-igual');

        $this->assertFalse($resultadoB['reenvio_detectado']);
        $this->assertNotSame($resultadoA['protocolo']->id, $resultadoB['protocolo']->id);
    }
}
