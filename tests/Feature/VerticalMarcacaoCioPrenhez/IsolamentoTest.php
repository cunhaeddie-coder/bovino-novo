<?php

namespace Tests\Feature\VerticalMarcacaoCioPrenhez;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ConfirmacaoPrenhezService;
use App\Services\MarcacaoCioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** INV-029 — isolamento entre Fazendas, mesmo padrão dos 16 verticais anteriores. */
class IsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_de_outra_fazenda_nao_ve_a_marcacao_de_cio(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $vaca = Animal::create(['fazenda_id' => $fazendaA, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo'])->id;
        $rufiao = Animal::create(['fazenda_id' => $fazendaA, 'lote_id' => null, 'custo_aquisicao' => 8000, 'status' => 'ativo'])->id;

        $service = app(MarcacaoCioService::class);
        $registro = $service->registrar($jose, $fazendaA, $vaca, $rufiao, '2026-01-01 08:00:00', 'marcacao-1');

        $this->assertNull($service->buscar($mariazinha, $registro['marcacao']->id));
        $this->assertNotNull($service->buscar($jose, $registro['marcacao']->id));
    }

    public function test_usuario_de_outra_fazenda_nao_ve_a_confirmacao_de_prenhez(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $vaca = Animal::create(['fazenda_id' => $fazendaA, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo'])->id;

        $service = app(ConfirmacaoPrenhezService::class);
        $registro = $service->registrar($jose, $fazendaA, $vaca, 'positivo', 'ultrassonografia', '2026-01-01 08:00:00', 'confirmacao-1');

        $this->assertNull($service->buscar($mariazinha, $registro['confirmacao']->id));
        $this->assertNotNull($service->buscar($jose, $registro['confirmacao']->id));
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

        $service = app(ConfirmacaoPrenhezService::class);
        $resultadoA = $service->registrar($jose, $fazendaA, $vacaA, 'positivo', 'ultrassonografia', '2026-01-01 08:00:00', 'chave-igual');
        $resultadoB = $service->registrar($mariazinha, $fazendaB, $vacaB, 'negativo', 'ultrassonografia', '2026-01-01 08:00:00', 'chave-igual');

        $this->assertFalse($resultadoB['reenvio_detectado']);
        $this->assertNotSame($resultadoA['confirmacao']->id, $resultadoB['confirmacao']->id);
    }
}
