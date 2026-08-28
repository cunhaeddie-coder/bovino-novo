<?php

namespace Tests\Feature\VerticalCompraInsumo;

use App\Models\Compra;
use App\Models\CompraInsumo;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Insumo;
use App\Models\ObrigacaoFinanceira;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer Service — SCHEMA-CONTRATO-COMPRA-INSUMO.md.
 * Não testa domínio (isso é o teste de domínio, depois de CompraInsumoService
 * existir) — testa que o schema em si só permite os estados que o contrato
 * descreve, e recusa os que não descreve.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_insumo_nome_e_unico_por_fazenda_nao_globalmente(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A']);
        $fazendaB = Fazenda::create(['nome' => 'B']);

        Insumo::create(['fazenda_id' => $fazendaA->id, 'nome' => 'Sal Branco 25kg']);

        // Mesmo nome, Fazenda diferente — precisa ser permitido (catálogos independentes).
        $insumoB = Insumo::create(['fazenda_id' => $fazendaB->id, 'nome' => 'Sal Branco 25kg']);
        $this->assertNotNull($insumoB->id);

        // Mesmo nome, MESMA Fazenda — precisa ser recusado (constraint real, não só Service).
        $this->assertThrows(
            fn () => Insumo::create(['fazenda_id' => $fazendaA->id, 'nome' => 'Sal Branco 25kg']),
            QueryException::class
        );
    }

    public function test_insumo_quantidade_e_valor_referencia_nascem_zero_por_padrao(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);

        $insumo = Insumo::create(['fazenda_id' => $fazenda->id, 'nome' => 'Fosbovi Advance 25kg']);

        $this->assertEquals(0, $insumo->fresh()->quantidade);
        $this->assertEquals(0, $insumo->fresh()->valor_referencia);
    }

    public function test_obrigacao_financeira_nao_pode_ter_compra_e_compra_insumo_ao_mesmo_tempo(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília']);
        $compra = Compra::create([
            'fazenda_id' => $fazenda->id, 'fornecedor_id' => $fornecedor->id,
            'chave_idempotencia' => 'y', 'data_compra' => '2026-01-01', 'valor_total' => 50,
        ]);
        $compraInsumo = CompraInsumo::create([
            'fazenda_id' => $fazenda->id, 'fornecedor_id' => $fornecedor->id,
            'chave_idempotencia' => 'x', 'data_compra' => '2026-01-01', 'valor_total' => 100,
        ]);

        // Os dois FKs válidos e preenchidos ao mesmo tempo — o guard de
        // mutualidade (booted()) precisa recusar antes de qualquer INSERT.
        $this->assertThrows(
            fn () => ObrigacaoFinanceira::create([
                'fazenda_id' => $fazenda->id, 'compra_id' => $compra->id, 'compra_insumo_id' => $compraInsumo->id, 'valor' => 100,
            ]),
            LogicException::class
        );
    }

    public function test_obrigacao_financeira_nao_pode_ter_nenhum_dos_dois(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);

        $this->assertThrows(
            fn () => ObrigacaoFinanceira::create([
                'fazenda_id' => $fazenda->id, 'compra_id' => null, 'compra_insumo_id' => null, 'valor' => 100,
            ]),
            LogicException::class
        );
    }

    public function test_chave_idempotencia_de_compra_insumo_e_unica_por_fazenda_nao_globalmente(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A']);
        $fazendaB = Fazenda::create(['nome' => 'B']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília']);

        CompraInsumo::create([
            'fazenda_id' => $fazendaA->id, 'fornecedor_id' => $fornecedor->id,
            'chave_idempotencia' => 'chave-x', 'data_compra' => '2026-01-01', 'valor_total' => 100,
        ]);

        // Mesma chave, Fazenda diferente — precisa ser permitido pelo schema.
        $compraB = CompraInsumo::create([
            'fazenda_id' => $fazendaB->id, 'fornecedor_id' => $fornecedor->id,
            'chave_idempotencia' => 'chave-x', 'data_compra' => '2026-01-01', 'valor_total' => 200,
        ]);
        $this->assertNotNull($compraB->id);

        // Mesma chave, MESMA Fazenda — precisa ser recusado pelo schema.
        $this->assertThrows(
            fn () => CompraInsumo::create([
                'fazenda_id' => $fazendaA->id, 'fornecedor_id' => $fornecedor->id,
                'chave_idempotencia' => 'chave-x', 'data_compra' => '2026-01-01', 'valor_total' => 999,
            ]),
            QueryException::class
        );
    }

    public function test_fks_obrigatorias_recusam_referencia_inexistente(): void
    {
        $this->assertThrows(
            fn () => CompraInsumo::create([
                'fazenda_id' => 999999, 'fornecedor_id' => 999999,
                'chave_idempotencia' => 'x', 'data_compra' => '2026-01-01', 'valor_total' => 100,
            ]),
            QueryException::class
        );
    }

    public function test_compra_insumo_e_imutavel_mesmo_via_query_builder(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília']);
        $compra = CompraInsumo::create([
            'fazenda_id' => $fazenda->id, 'fornecedor_id' => $fornecedor->id,
            'chave_idempotencia' => 'x', 'data_compra' => '2026-01-01', 'valor_total' => 100,
        ]);

        $this->assertThrows(fn () => $compra->update(['valor_total' => 999]), LogicException::class);
        $this->assertThrows(fn () => CompraInsumo::where('id', $compra->id)->update(['valor_total' => 999]), LogicException::class);
        $this->assertThrows(fn () => CompraInsumo::where('id', $compra->id)->delete(), LogicException::class);
    }
}
