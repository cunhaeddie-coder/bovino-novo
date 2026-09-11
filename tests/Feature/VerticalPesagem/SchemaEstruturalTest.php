<?php

namespace Tests\Feature\VerticalPesagem;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Pesagem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer PesagemService —
 * SCHEMA-CONTRATO-PESAGEM.md. Mesma filosofia dos 18 verticais anteriores:
 * testa que o schema em si (migrations + guards de Model) só permite os
 * estados que o contrato descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    private function criarAnimal(int $fazendaId): Animal
    {
        return Animal::create(['fazenda_id' => $fazendaId, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo']);
    }

    public function test_pesagem_e_imutavel_depois_de_registrada(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $animal = $this->criarAnimal($fazenda->id);
        $pesagem = Pesagem::create([
            'fazenda_id' => $fazenda->id, 'animal_id' => $animal->id, 'peso' => 280.0,
            'data_pesagem' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'x',
        ]);

        $this->assertThrows(
            fn () => $pesagem->update(['peso' => 300.0]),
            LogicException::class
        );
    }

    public function test_pesagem_e_unica_por_fazenda_e_chave_idempotencia(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $animal = $this->criarAnimal($fazenda->id);
        Pesagem::create([
            'fazenda_id' => $fazenda->id, 'animal_id' => $animal->id, 'peso' => 280.0,
            'data_pesagem' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'mesma-chave',
        ]);

        $this->assertThrows(
            fn () => Pesagem::create([
                'fazenda_id' => $fazenda->id, 'animal_id' => $animal->id, 'peso' => 281.0,
                'data_pesagem' => '2026-01-02 08:00:00', 'chave_idempotencia' => 'mesma-chave',
            ]),
            QueryException::class
        );
    }

    /** Sem UNIQUE(animal_id, data_pesagem) — pesagem de conferência no mesmo dia é legítima. */
    public function test_mesmo_animal_pode_ser_pesado_duas_vezes_no_mesmo_dia(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $animal = $this->criarAnimal($fazenda->id);
        Pesagem::create([
            'fazenda_id' => $fazenda->id, 'animal_id' => $animal->id, 'peso' => 280.0,
            'data_pesagem' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'manha',
        ]);
        $segunda = Pesagem::create([
            'fazenda_id' => $fazenda->id, 'animal_id' => $animal->id, 'peso' => 279.5,
            'data_pesagem' => '2026-01-01 17:00:00', 'chave_idempotencia' => 'conferencia',
        ]);

        $this->assertSame(2, Pesagem::where('animal_id', $animal->id)->count());
        $this->assertEqualsWithDelta(279.5, (float) $segunda->peso, 0.01);
    }

    public function test_data_pesagem_preserva_hora(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $animal = $this->criarAnimal($fazenda->id);
        $pesagem = Pesagem::create([
            'fazenda_id' => $fazenda->id, 'animal_id' => $animal->id, 'peso' => 280.0,
            'data_pesagem' => '2026-03-05 06:30:00', 'chave_idempotencia' => 'x',
        ]);

        $this->assertSame('2026-03-05 06:30:00', $pesagem->data_pesagem->format('Y-m-d H:i:s'));
    }
}
