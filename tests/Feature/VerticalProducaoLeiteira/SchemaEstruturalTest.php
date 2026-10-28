<?php

namespace Tests\Feature\VerticalProducaoLeiteira;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\ProducaoLeiteira;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer ProducaoLeiteiraService —
 * SCHEMA-CONTRATO-PRODUCAO-LEITEIRA.md. Mesma filosofia dos 13 verticais
 * anteriores: testa que o schema em si (migrations + guards de Model) só
 * permite os estados que o contrato descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    private function criarAnimal(int $fazendaId): Animal
    {
        return Animal::create(['fazenda_id' => $fazendaId, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo']);
    }

    public function test_producao_leiteira_e_imutavel_depois_de_registrada(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $animal = $this->criarAnimal($fazenda->id);
        $producao = ProducaoLeiteira::create([
            'fazenda_id' => $fazenda->id, 'animal_id' => $animal->id, 'data_producao' => '2026-01-01 18:00:00',
            'quantidade_total' => 10, 'quantidade_vendida' => 6, 'chave_idempotencia' => 'x',
        ]);

        $this->assertThrows(
            fn () => $producao->update(['quantidade_total' => 12]),
            LogicException::class
        );
    }

    public function test_vendido_nao_pode_exceder_total_inv039(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $animal = $this->criarAnimal($fazenda->id);

        $this->assertThrows(
            fn () => ProducaoLeiteira::create([
                'fazenda_id' => $fazenda->id, 'animal_id' => $animal->id, 'data_producao' => '2026-01-01 18:00:00',
                'quantidade_total' => 10, 'quantidade_vendida' => 11, 'chave_idempotencia' => 'x',
            ]),
            LogicException::class
        );
    }

    public function test_vendido_igual_ao_total_e_aceito(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $animal = $this->criarAnimal($fazenda->id);
        $producao = ProducaoLeiteira::create([
            'fazenda_id' => $fazenda->id, 'animal_id' => $animal->id, 'data_producao' => '2026-01-01 18:00:00',
            'quantidade_total' => 10, 'quantidade_vendida' => 10, 'chave_idempotencia' => 'x',
        ]);

        $this->assertEqualsWithDelta(10.0, (float) $producao->quantidade_total, 0.01);
    }

    /** Decisão do produtor (28/10/2026): o total do rebanho não tem vaca, e o leite pro bezerro não existe mais. */
    public function test_total_do_rebanho_sem_vaca_e_sem_coluna_do_bezerro(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $producao = ProducaoLeiteira::create([
            'fazenda_id' => $fazenda->id, 'animal_id' => null, 'data_producao' => '2026-01-01 18:00:00',
            'quantidade_total' => 380, 'quantidade_vendida' => 380, 'chave_idempotencia' => 'x',
        ]);

        $this->assertNull($producao->fresh()->animal_id);
        $this->assertFalse(Schema::hasColumn('producoes_leiteiras', 'quantidade_bezerro'));
    }

    public function test_producao_leiteira_e_unica_por_fazenda_e_chave_idempotencia(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $animal = $this->criarAnimal($fazenda->id);
        ProducaoLeiteira::create([
            'fazenda_id' => $fazenda->id, 'animal_id' => $animal->id, 'data_producao' => '2026-01-01 18:00:00',
            'quantidade_total' => 10, 'quantidade_vendida' => 6, 'chave_idempotencia' => 'mesma-chave',
        ]);

        $this->assertThrows(
            fn () => ProducaoLeiteira::create([
                'fazenda_id' => $fazenda->id, 'animal_id' => $animal->id, 'data_producao' => '2026-01-02 18:00:00',
                'quantidade_total' => 8, 'quantidade_vendida' => 8, 'chave_idempotencia' => 'mesma-chave',
            ]),
            QueryException::class
        );
    }
}
