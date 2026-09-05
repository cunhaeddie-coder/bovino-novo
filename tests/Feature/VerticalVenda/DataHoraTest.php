<?php

namespace Tests\Feature\VerticalVenda;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Models\Venda;
use App\Services\VendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * GATE-DECISAO-DOMINIO-DATA-HORA.md — decisão direta do produtor (04/09/2026):
 * venda, confirmação da venda, compra e confirmação da compra precisam
 * constar com data E hora, sem exceção.
 */
class DataHoraTest extends TestCase
{
    use RefreshDatabase;

    public function test_venda_sem_data_venda_e_recusada(): void
    {
        $this->assertThrows(
            fn () => Venda::create([
                'fazenda_id' => Fazenda::create(['nome' => 'A'])->id,
                'chave_idempotencia' => 'x', 'animal_ids' => [],
                'valor_bruto' => 100, 'cpv' => 0, 'deducao_fiscal' => 0,
                'fiscal_e_premissa' => true, 'receita_liquida' => 100,
            ]),
            LogicException::class
        );
    }

    public function test_venda_service_grava_data_e_hora_reais_declaradas(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazenda, 'papel' => 'dono']);
        $animal = Animal::create(['fazenda_id' => $fazenda, 'lote_id' => null, 'custo_aquisicao' => 100, 'status' => 'ativo'])->id;

        $resultado = app(VendaService::class)->registrar($jose, $fazenda, [$animal], 500.00, '2026-03-10 14:30:00', 'venda-com-hora');

        $this->assertSame('2026-03-10 14:30:00', $resultado['venda']->data_venda->format('Y-m-d H:i:s'), 'data_e_hora_declaradas_preservadas_exatas');
    }

    public function test_correcao_de_venda_tem_sua_propria_data_venda_distinta_da_original(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazenda, 'papel' => 'dono']);
        $a1 = Animal::create(['fazenda_id' => $fazenda, 'lote_id' => null, 'custo_aquisicao' => 100, 'status' => 'ativo'])->id;
        $a2 = Animal::create(['fazenda_id' => $fazenda, 'lote_id' => null, 'custo_aquisicao' => 100, 'status' => 'ativo'])->id;

        $vendas = app(VendaService::class);
        $original = $vendas->registrar($jose, $fazenda, [$a1, $a2], 1000.00, '2026-01-01 09:00:00', 'venda-original');
        $correcao = $vendas->corrigir($jose, $original['venda']->id, [$a1], 500.00, 'correcao-1');

        $this->assertNotNull($correcao['correcao']->data_venda, 'correcao_tambem_tem_data_venda_preenchida');
        $this->assertNotSame(
            $original['venda']->data_venda->format('Y-m-d H:i:s'),
            $correcao['correcao']->data_venda->format('Y-m-d H:i:s'),
            'correcao_e_seu_proprio_fato_com_seu_proprio_momento_real'
        );
    }
}
