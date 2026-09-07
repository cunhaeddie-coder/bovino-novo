<?php

namespace Tests\Feature\VerticalEventoSaude;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Insumo;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\EventoSaudeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** INV-029 — isolamento entre Fazendas, mesmo padrão dos 10 verticais anteriores. */
class IsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_de_outra_fazenda_nao_ve_o_evento(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $insumo = Insumo::create(['fazenda_id' => $fazendaA, 'nome' => 'Vacina', 'quantidade' => 100])->id;
        $animal = Animal::create(['fazenda_id' => $fazendaA, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id;

        $service = app(EventoSaudeService::class);
        $registro = $service->registrar($jose, $fazendaA, [$animal], $insumo, 5.0, 'vacina', '2026-01-01 08:00:00', 'evento-1');

        $this->assertNull($service->buscar($mariazinha, $registro['evento']->id));
        $this->assertNotNull($service->buscar($jose, $registro['evento']->id));
    }

    public function test_chaves_iguais_em_fazendas_diferentes_nao_sao_reenvio(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $insumoA = Insumo::create(['fazenda_id' => $fazendaA, 'nome' => 'Vacina', 'quantidade' => 100])->id;
        $insumoB = Insumo::create(['fazenda_id' => $fazendaB, 'nome' => 'Vacina', 'quantidade' => 100])->id;
        $animalA = Animal::create(['fazenda_id' => $fazendaA, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id;
        $animalB = Animal::create(['fazenda_id' => $fazendaB, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id;

        $service = app(EventoSaudeService::class);
        $resultadoA = $service->registrar($jose, $fazendaA, [$animalA], $insumoA, 5.0, 'vacina', '2026-01-01 08:00:00', 'chave-igual');
        $resultadoB = $service->registrar($mariazinha, $fazendaB, [$animalB], $insumoB, 5.0, 'vacina', '2026-01-01 08:00:00', 'chave-igual');

        $this->assertFalse($resultadoB['reenvio_detectado']);
        $this->assertNotSame($resultadoA['evento']->id, $resultadoB['evento']->id);
    }
}
