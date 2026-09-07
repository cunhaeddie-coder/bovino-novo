<?php

namespace Tests\Feature\VerticalSeparacaoVenda;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\SeparacaoVendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** INV-029 — isolamento entre Fazendas, mesmo padrão dos 7 verticais anteriores. */
class IsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_de_outra_fazenda_nao_ve_a_separacao(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $animal = Animal::create(['fazenda_id' => $fazendaA, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id;

        $service = app(SeparacaoVendaService::class);
        $registro = $service->registrar($jose, $fazendaA, [$animal], 1000.00, '2026-01-01 08:00:00', 'separacao-1');

        $this->assertNull($service->buscar($mariazinha, $registro['separacao']->id));
        $this->assertNotNull($service->buscar($jose, $registro['separacao']->id));
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

        $service = app(SeparacaoVendaService::class);
        $resultadoA = $service->registrar($jose, $fazendaA, [$animalA], 1000.00, '2026-01-01 08:00:00', 'chave-igual');
        $resultadoB = $service->registrar($mariazinha, $fazendaB, [$animalB], 2000.00, '2026-01-01 08:00:00', 'chave-igual');

        $this->assertFalse($resultadoB['reenvio_detectado']);
        $this->assertNotSame($resultadoA['separacao']->id, $resultadoB['separacao']->id);
    }
}
