<?php

namespace Tests\Feature\VerticalMorte;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\MorteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** INV-029 — isolamento entre Fazendas, mesmo padrão dos 7 verticais anteriores. */
class IsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_de_outra_fazenda_nao_ve_a_morte(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $animal = Animal::create(['fazenda_id' => $fazendaA, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id;

        $service = app(MorteService::class);
        $resultado = $service->registrar($jose, $fazendaA, [$animal], 'doença', '2026-01-01 08:00:00', 'morte-1');

        $this->assertNull($service->buscar($mariazinha, $resultado['morte']->id));
        $this->assertNotNull($service->buscar($jose, $resultado['morte']->id));
    }

    public function test_usuario_de_terceira_fazenda_nao_pode_matar_animal_de_outra(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $intruso = Usuario::create(['nome' => 'Intruso'])->id;
        $animal = Animal::create(['fazenda_id' => $fazendaA, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id;

        $this->assertThrows(
            fn () => app(MorteService::class)->registrar($intruso, $fazendaA, [$animal], 'doença', '2026-01-01 08:00:00', 'ataque'),
            \DomainException::class
        );
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

        $service = app(MorteService::class);
        $resultadoA = $service->registrar($jose, $fazendaA, [$animalA], 'doença', '2026-01-01 08:00:00', 'chave-igual');
        $resultadoB = $service->registrar($mariazinha, $fazendaB, [$animalB], 'acidente', '2026-01-01 08:00:00', 'chave-igual');

        $this->assertFalse($resultadoB['reenvio_detectado']);
        $this->assertNotSame($resultadoA['morte']->id, $resultadoB['morte']->id);
    }
}
