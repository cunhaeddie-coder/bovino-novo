<?php

namespace Tests\Feature\VerticalConfiguracaoFiscal;

use App\Models\Fazenda;
use App\Models\LancamentoFiscal;
use App\Models\Venda;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer Service — SCHEMA-CONTRATO-
 * CONFIGURACAO-FISCAL.md. Mesma filosofia dos 28 verticais anteriores:
 * testa que o schema em si (migrations) só permite os estados que o
 * contrato descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    private function criarVenda(int $fazendaId): Venda
    {
        return Venda::create([
            'fazenda_id' => $fazendaId, 'chave_idempotencia' => 'venda-'.uniqid(),
            'animal_ids' => [], 'data_venda' => '2026-01-01 10:00:00', 'valor_bruto' => 1000,
            'cpv' => 0, 'deducao_fiscal' => 0, 'fiscal_e_premissa' => true, 'receita_liquida' => 1000,
        ]);
    }

    public function test_lancamento_fiscal_aceita_criacao_valida_com_taxa(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $venda = $this->criarVenda($fazenda->id);

        $lancamento = LancamentoFiscal::create([
            'venda_id' => $venda->id, 'deducao_fiscal' => 23.00, 'taxa_aplicada' => 0.023,
            'fiscal_e_premissa' => false, 'data_lancamento' => now(),
        ]);

        $this->assertNotNull($lancamento->fresh());
    }

    public function test_lancamento_fiscal_aceita_taxa_aplicada_nula(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $venda = $this->criarVenda($fazenda->id);

        $lancamento = LancamentoFiscal::create([
            'venda_id' => $venda->id, 'deducao_fiscal' => 0.00, 'taxa_aplicada' => null,
            'fiscal_e_premissa' => true, 'data_lancamento' => now(),
        ]);

        $this->assertNull($lancamento->fresh()->taxa_aplicada);
    }

    /** INV-061 — toda Venda tem exatamente um LancamentoFiscal. */
    public function test_venda_id_e_unico_em_lancamentos_fiscais(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $venda = $this->criarVenda($fazenda->id);
        LancamentoFiscal::create(['venda_id' => $venda->id, 'deducao_fiscal' => 0, 'fiscal_e_premissa' => true, 'data_lancamento' => now()]);

        $this->expectException(QueryException::class);
        LancamentoFiscal::create(['venda_id' => $venda->id, 'deducao_fiscal' => 0, 'fiscal_e_premissa' => true, 'data_lancamento' => now()]);
    }
}
