<?php

namespace Tests\Feature\VerticalProducaoLeiteira;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ProducaoLeiteiraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** INV-029 — isolamento entre Fazendas, mesmo padrão dos 13 verticais anteriores. */
class IsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_de_outra_fazenda_nao_ve_a_producao(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $vaca = Animal::create(['fazenda_id' => $fazendaA, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo'])->id;

        $service = app(ProducaoLeiteiraService::class);
        $registro = $service->registrar($jose, $fazendaA, $vaca, '2026-01-01 18:00:00', 10.0, 6.0, 4.0, 'producao-1');

        $this->assertNull($service->buscar($mariazinha, $registro['producao']->id));
        $this->assertNotNull($service->buscar($jose, $registro['producao']->id));
    }

    public function test_chaves_iguais_em_fazendas_diferentes_nao_sao_reenvio(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $vacaA = Animal::create(['fazenda_id' => $fazendaA, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo'])->id;
        $vacaB = Animal::create(['fazenda_id' => $fazendaB, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo'])->id;

        $service = app(ProducaoLeiteiraService::class);
        $resultadoA = $service->registrar($jose, $fazendaA, $vacaA, '2026-01-01 18:00:00', 10.0, 6.0, 4.0, 'chave-igual');
        $resultadoB = $service->registrar($mariazinha, $fazendaB, $vacaB, '2026-01-01 18:00:00', 8.0, 8.0, 0.0, 'chave-igual');

        $this->assertFalse($resultadoB['reenvio_detectado']);
        $this->assertNotSame($resultadoA['producao']->id, $resultadoB['producao']->id);
    }
}
