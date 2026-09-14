<?php

namespace Tests\Feature\VerticalInteligenciaMercado;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Venda;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer IntelligenciaMercadoService —
 * SCHEMA-CONTRATO-INTELIGENCIA-MERCADO.md. Mesma filosofia dos 20 verticais
 * anteriores: testa que o schema em si (migrations + guards de Model) só
 * permite os estados que o contrato descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_animal_aceita_raca_nula_e_raca_preenchida(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $semRaca = Animal::create(['fazenda_id' => $fazenda->id, 'custo_aquisicao' => 100]);
        $comRaca = Animal::create(['fazenda_id' => $fazenda->id, 'custo_aquisicao' => 100, 'raca' => 'Nelore']);

        $this->assertNull($semRaca->fresh()->raca);
        $this->assertSame('Nelore', $comRaca->fresh()->raca);
    }

    public function test_fazenda_aceita_estado_nulo_e_estado_preenchido(): void
    {
        $semEstado = Fazenda::create(['nome' => 'A']);
        $comEstado = Fazenda::create(['nome' => 'B', 'estado' => 'MT']);

        $this->assertNull($semEstado->fresh()->estado);
        $this->assertSame('MT', $comEstado->fresh()->estado);
    }

    public function test_venda_animal_e_unica_por_venda_e_animal(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $animal = Animal::create(['fazenda_id' => $fazenda->id, 'custo_aquisicao' => 100, 'status' => 'vendido']);
        $venda = Venda::create([
            'fazenda_id' => $fazenda->id, 'chave_idempotencia' => 'x', 'animal_ids' => [$animal->id],
            'data_venda' => '2026-01-01 10:00:00', 'valor_bruto' => 1000, 'cpv' => 100,
            'deducao_fiscal' => 0, 'fiscal_e_premissa' => true, 'receita_liquida' => 900,
        ]);

        $venda->animais()->attach($animal->id);

        $this->assertThrows(
            fn () => $venda->animais()->attach($animal->id),
            QueryException::class
        );
    }

    public function test_venda_carrega_seus_animais_via_pivot(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $a1 = Animal::create(['fazenda_id' => $fazenda->id, 'custo_aquisicao' => 100, 'status' => 'vendido', 'raca' => 'Nelore']);
        $a2 = Animal::create(['fazenda_id' => $fazenda->id, 'custo_aquisicao' => 100, 'status' => 'vendido', 'raca' => 'Angus']);
        $venda = Venda::create([
            'fazenda_id' => $fazenda->id, 'chave_idempotencia' => 'x', 'animal_ids' => [$a1->id, $a2->id],
            'data_venda' => '2026-01-01 10:00:00', 'valor_bruto' => 2000, 'cpv' => 200,
            'deducao_fiscal' => 0, 'fiscal_e_premissa' => true, 'receita_liquida' => 1800,
        ]);
        $venda->animais()->attach([$a1->id, $a2->id]);

        $racas = $venda->animais()->pluck('raca')->sort()->values()->all();
        $this->assertSame(['Angus', 'Nelore'], $racas);
    }
}
