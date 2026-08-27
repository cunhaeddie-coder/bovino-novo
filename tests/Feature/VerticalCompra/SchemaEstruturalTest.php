<?php

namespace Tests\Feature\VerticalCompra;

use App\Models\Animal;
use App\Models\Compra;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Lote;
use App\Models\Papel;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer Service — SCHEMA-CONTRATO-COMPRA.md.
 * Não testa domínio (isso é o teste de domínio, depois de CompraService
 * existir) — testa que o schema em si só permite os estados que o contrato
 * descreve, e recusa os que não descreve.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_lote_id_e_realmente_nullable(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);

        $animal = Animal::create([
            'fazenda_id' => $fazenda->id,
            'lote_id' => null,
            'custo_aquisicao' => 15000.00,
            'status' => 'ativo',
        ]);

        $this->assertNull($animal->fresh()->lote_id);
        $this->assertEquals(15000.00, $animal->fresh()->custo_aquisicao);
    }

    public function test_animal_nao_pode_ter_lote_e_custo_aquisicao_ao_mesmo_tempo(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $lote = Lote::create(['fazenda_id' => $fazenda->id, 'qtd_animais' => 1, 'custo_aquisicao' => 100]);

        $this->assertThrows(
            fn () => Animal::create(['fazenda_id' => $fazenda->id, 'lote_id' => $lote->id, 'custo_aquisicao' => 15000.00, 'status' => 'ativo']),
            LogicException::class
        );
    }

    public function test_animal_nao_pode_ter_nenhum_dos_dois(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);

        $this->assertThrows(
            fn () => Animal::create(['fazenda_id' => $fazenda->id, 'lote_id' => null, 'custo_aquisicao' => null, 'status' => 'ativo']),
            LogicException::class
        );
    }

    public function test_chave_idempotencia_e_unica_por_fazenda_nao_globalmente(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A']);
        $fazendaB = Fazenda::create(['nome' => 'B']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília']);

        Compra::create([
            'fazenda_id' => $fazendaA->id, 'fornecedor_id' => $fornecedor->id,
            'chave_idempotencia' => 'chave-x', 'data_compra' => '2026-01-01', 'valor_total' => 100,
        ]);

        // Mesma chave, Fazenda diferente — precisa ser permitido pelo schema.
        $compraB = Compra::create([
            'fazenda_id' => $fazendaB->id, 'fornecedor_id' => $fornecedor->id,
            'chave_idempotencia' => 'chave-x', 'data_compra' => '2026-01-01', 'valor_total' => 200,
        ]);
        $this->assertNotNull($compraB->id);

        // Mesma chave, MESMA Fazenda — precisa ser recusado pelo schema (constraint real, não só Service).
        $this->assertThrows(
            fn () => Compra::create([
                'fazenda_id' => $fazendaA->id, 'fornecedor_id' => $fornecedor->id,
                'chave_idempotencia' => 'chave-x', 'data_compra' => '2026-01-01', 'valor_total' => 999,
            ]),
            QueryException::class
        );
    }

    public function test_fks_obrigatorias_recusam_referencia_inexistente(): void
    {
        $this->assertThrows(
            fn () => Compra::create([
                'fazenda_id' => 999999, 'fornecedor_id' => 999999,
                'chave_idempotencia' => 'x', 'data_compra' => '2026-01-01', 'valor_total' => 100,
            ]),
            QueryException::class
        );
    }

    public function test_compra_e_imutavel_mesmo_via_query_builder(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília']);
        $compra = Compra::create([
            'fazenda_id' => $fazenda->id, 'fornecedor_id' => $fornecedor->id,
            'chave_idempotencia' => 'x', 'data_compra' => '2026-01-01', 'valor_total' => 100,
        ]);

        $this->assertThrows(fn () => $compra->update(['valor_total' => 999]), LogicException::class);
        $this->assertThrows(fn () => Compra::where('id', $compra->id)->update(['valor_total' => 999]), LogicException::class);
        $this->assertThrows(fn () => Compra::where('id', $compra->id)->delete(), LogicException::class);
    }

    public function test_isolamento_papeis_distingue_usuarios_de_fazendas_diferentes(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A']);
        $fazendaB = Fazenda::create(['nome' => 'B']);
        $jose = Usuario::create(['nome' => 'José']);
        Papel::create(['usuario_id' => $jose->id, 'fazenda_id' => $fazendaA->id, 'papel' => 'dono']);

        $this->assertTrue($jose->temRelacaoComFazenda($fazendaA->id));
        $this->assertFalse($jose->temRelacaoComFazenda($fazendaB->id));
    }
}
