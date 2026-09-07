<?php

namespace Tests\Feature\VerticalSeparacaoVenda;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\SeparacaoVenda;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer SeparacaoVendaService —
 * SCHEMA-CONTRATO-SEPARACAO-VENDA.md. Mesma filosofia dos 7 verticais
 * anteriores: testa que o schema em si (migrations + guards de Model) só
 * permite os estados que o contrato descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_separacao_so_pode_ser_concluida_com_venda_id(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $a1 = Animal::create(['fazenda_id' => $fazenda->id, 'lote_id' => null, 'custo_aquisicao' => 100, 'status' => 'ativo']);

        $this->assertThrows(
            fn () => SeparacaoVenda::create([
                'fazenda_id' => $fazenda->id, 'animal_ids' => [$a1->id], 'valor_total' => 1000,
                'status' => 'concluida', 'data_separacao' => '2026-01-01 10:00:00', 'chave_idempotencia' => 'x',
            ]),
            LogicException::class
        );
    }

    public function test_separacao_concluida_e_terminal(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $a1 = Animal::create(['fazenda_id' => $fazenda->id, 'lote_id' => null, 'custo_aquisicao' => 100, 'status' => 'ativo']);
        $venda = Venda::create([
            'fazenda_id' => $fazenda->id, 'chave_idempotencia' => 'venda-x', 'animal_ids' => [$a1->id],
            'data_venda' => '2026-01-01 10:00:00', 'valor_bruto' => 1000, 'cpv' => 100,
            'deducao_fiscal' => 0, 'fiscal_e_premissa' => true, 'receita_liquida' => 900,
        ]);
        $separacao = SeparacaoVenda::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [$a1->id], 'valor_total' => 1000,
            'status' => 'concluida', 'data_separacao' => '2026-01-01 10:00:00', 'data_conclusao' => '2026-01-01 11:00:00',
            'venda_id' => $venda->id, 'chave_idempotencia' => 'x',
        ]);

        $this->assertThrows(
            fn () => $separacao->update(['valor_total' => 999]),
            LogicException::class
        );
    }

    public function test_separacao_aberta_aceita_transicao_normal_para_concluida(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $a1 = Animal::create(['fazenda_id' => $fazenda->id, 'lote_id' => null, 'custo_aquisicao' => 100, 'status' => 'ativo']);
        $venda = Venda::create([
            'fazenda_id' => $fazenda->id, 'chave_idempotencia' => 'venda-x', 'animal_ids' => [$a1->id],
            'data_venda' => '2026-01-01 10:00:00', 'valor_bruto' => 1000, 'cpv' => 100,
            'deducao_fiscal' => 0, 'fiscal_e_premissa' => true, 'receita_liquida' => 900,
        ]);
        $separacao = SeparacaoVenda::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [$a1->id], 'valor_total' => 1000,
            'status' => 'aberta', 'data_separacao' => '2026-01-01 10:00:00', 'chave_idempotencia' => 'x',
        ]);

        $separacao->update(['status' => 'concluida', 'venda_id' => $venda->id, 'data_conclusao' => '2026-01-01 11:00:00']);
        $this->assertSame('concluida', $separacao->fresh()->status);
    }
}
