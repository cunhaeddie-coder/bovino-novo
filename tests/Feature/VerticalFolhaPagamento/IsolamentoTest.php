<?php

namespace Tests\Feature\VerticalFolhaPagamento;

use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\FuncionarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** INV-029 — isolamento entre Fazendas, mesmo padrão dos 9 verticais anteriores. */
class IsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_de_outra_fazenda_nao_ve_o_funcionario(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);

        $service = app(FuncionarioService::class);
        $registro = $service->contratar($jose, $fazendaA, 'Eddie', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', 'funcionario-1');

        $this->assertNull($service->buscar($mariazinha, $registro['funcionario']->id));
        $this->assertNotNull($service->buscar($jose, $registro['funcionario']->id));
    }

    public function test_chaves_iguais_em_fazendas_diferentes_nao_sao_reenvio(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);

        $service = app(FuncionarioService::class);
        $resultadoA = $service->contratar($jose, $fazendaA, 'Eddie', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', 'chave-igual');
        $resultadoB = $service->contratar($mariazinha, $fazendaB, 'Outro', 'gerente', 5000.00, '2026-01-01 08:00:00', 'chave-igual');

        $this->assertFalse($resultadoB['reenvio_detectado']);
        $this->assertNotSame($resultadoA['funcionario']->id, $resultadoB['funcionario']->id);
    }
}
