<?php

namespace Tests\Feature\VerticalGta;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Gta;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer GtaService — SCHEMA-CONTRATO-GTA.md.
 * Mesma filosofia dos 6 verticais anteriores: testa que o schema em si
 * (migrations + guards de Model) só permite os estados que o contrato
 * descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_quantidade_declarada_precisa_bater_com_animal_ids_inv036(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $a1 = Animal::create(['fazenda_id' => $fazenda->id, 'lote_id' => null, 'custo_aquisicao' => 100, 'status' => 'ativo']);

        $this->assertThrows(
            fn () => Gta::create([
                'fazenda_id' => $fazenda->id, 'animal_ids' => [$a1->id], 'destino' => 'Frigorífico X',
                'quantidade_declarada' => 2, 'valor_bruto' => 1000, 'status' => 'emitida',
                'data_emissao' => '2026-01-01 10:00:00', 'chave_idempotencia' => 'x',
            ]),
            LogicException::class
        );
    }

    public function test_gta_so_pode_ser_concluida_com_venda_id_inv037(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $a1 = Animal::create(['fazenda_id' => $fazenda->id, 'lote_id' => null, 'custo_aquisicao' => 100, 'status' => 'ativo']);

        $this->assertThrows(
            fn () => Gta::create([
                'fazenda_id' => $fazenda->id, 'animal_ids' => [$a1->id], 'destino' => 'Frigorífico X',
                'quantidade_declarada' => 1, 'valor_bruto' => 1000, 'status' => 'concluida',
                'data_emissao' => '2026-01-01 10:00:00', 'chave_idempotencia' => 'x',
            ]),
            LogicException::class
        );
    }

    public function test_gta_concluida_e_terminal(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $a1 = Animal::create(['fazenda_id' => $fazenda->id, 'lote_id' => null, 'custo_aquisicao' => 100, 'status' => 'ativo']);
        $venda = Venda::create([
            'fazenda_id' => $fazenda->id, 'chave_idempotencia' => 'venda-x', 'animal_ids' => [$a1->id],
            'data_venda' => '2026-01-01 10:00:00', 'valor_bruto' => 1000, 'cpv' => 100,
            'deducao_fiscal' => 0, 'fiscal_e_premissa' => true, 'receita_liquida' => 900,
        ]);
        $gta = Gta::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [$a1->id], 'destino' => 'Frigorífico X',
            'quantidade_declarada' => 1, 'valor_bruto' => 1000, 'status' => 'concluida',
            'data_emissao' => '2026-01-01 10:00:00', 'data_conclusao' => '2026-01-01 11:00:00',
            'venda_id' => $venda->id, 'chave_idempotencia' => 'x',
        ]);

        $this->assertThrows(
            fn () => $gta->update(['destino' => 'Outro lugar']),
            LogicException::class
        );
    }

    public function test_gta_emitida_aceita_transicao_normal_para_concluida(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $a1 = Animal::create(['fazenda_id' => $fazenda->id, 'lote_id' => null, 'custo_aquisicao' => 100, 'status' => 'ativo']);
        $venda = Venda::create([
            'fazenda_id' => $fazenda->id, 'chave_idempotencia' => 'venda-x', 'animal_ids' => [$a1->id],
            'data_venda' => '2026-01-01 10:00:00', 'valor_bruto' => 1000, 'cpv' => 100,
            'deducao_fiscal' => 0, 'fiscal_e_premissa' => true, 'receita_liquida' => 900,
        ]);
        $gta = Gta::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [$a1->id], 'destino' => 'Frigorífico X',
            'quantidade_declarada' => 1, 'valor_bruto' => 1000, 'status' => 'emitida',
            'data_emissao' => '2026-01-01 10:00:00', 'chave_idempotencia' => 'x',
        ]);

        $gta->update(['status' => 'concluida', 'venda_id' => $venda->id, 'data_conclusao' => '2026-01-01 11:00:00']);
        $this->assertSame('concluida', $gta->fresh()->status);
    }
}
