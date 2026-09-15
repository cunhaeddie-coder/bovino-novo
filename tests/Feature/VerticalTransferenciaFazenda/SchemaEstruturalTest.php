<?php

namespace Tests\Feature\VerticalTransferenciaFazenda;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\TransferenciaAnimal;
use App\Models\TransferenciaFazenda;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer Service — SCHEMA-CONTRATO-
 * TRANSFERENCIA-FAZENDA.md. Mesma filosofia dos 25 verticais anteriores:
 * testa que o schema em si (migrations) só permite os estados que o
 * contrato descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_transferencia_fazenda_aceita_criacao_valida(): void
    {
        $origem = Fazenda::create(['nome' => 'A']);
        $destino = Fazenda::create(['nome' => 'B']);

        $transferencia = TransferenciaFazenda::create([
            'fazenda_origem_id' => $origem->id,
            'fazenda_destino_id' => $destino->id,
            'chave_idempotencia' => 'chave-1',
            'data_transferencia' => now(),
        ]);

        $this->assertNotNull($transferencia->fresh());
    }

    public function test_chave_idempotencia_e_unica_por_fazenda_origem(): void
    {
        $origem = Fazenda::create(['nome' => 'A']);
        $destino = Fazenda::create(['nome' => 'B']);

        TransferenciaFazenda::create([
            'fazenda_origem_id' => $origem->id,
            'fazenda_destino_id' => $destino->id,
            'chave_idempotencia' => 'chave-1',
            'data_transferencia' => now(),
        ]);

        $this->expectException(QueryException::class);
        TransferenciaFazenda::create([
            'fazenda_origem_id' => $origem->id,
            'fazenda_destino_id' => $destino->id,
            'chave_idempotencia' => 'chave-1',
            'data_transferencia' => now(),
        ]);
    }

    public function test_mesma_chave_idempotencia_e_permitida_pra_outra_fazenda_origem(): void
    {
        $origemA = Fazenda::create(['nome' => 'A']);
        $origemB = Fazenda::create(['nome' => 'B']);
        $destino = Fazenda::create(['nome' => 'C']);

        TransferenciaFazenda::create([
            'fazenda_origem_id' => $origemA->id,
            'fazenda_destino_id' => $destino->id,
            'chave_idempotencia' => 'chave-1',
            'data_transferencia' => now(),
        ]);

        $transferenciaB = TransferenciaFazenda::create([
            'fazenda_origem_id' => $origemB->id,
            'fazenda_destino_id' => $destino->id,
            'chave_idempotencia' => 'chave-1',
            'data_transferencia' => now(),
        ]);

        $this->assertNotNull($transferenciaB->fresh());
    }

    public function test_transferencia_animal_aceita_criacao_valida(): void
    {
        $origem = Fazenda::create(['nome' => 'A']);
        $destino = Fazenda::create(['nome' => 'B']);
        $transferencia = TransferenciaFazenda::create([
            'fazenda_origem_id' => $origem->id,
            'fazenda_destino_id' => $destino->id,
            'chave_idempotencia' => 'chave-1',
            'data_transferencia' => now(),
        ]);
        $animalOrigem = Animal::create(['fazenda_id' => $origem->id, 'custo_aquisicao' => 1000, 'status' => 'transferido']);
        $animalDestino = Animal::create(['fazenda_id' => $destino->id, 'custo_aquisicao' => 1000, 'status' => 'ativo']);

        $item = TransferenciaAnimal::create([
            'transferencia_id' => $transferencia->id,
            'animal_origem_id' => $animalOrigem->id,
            'animal_destino_id' => $animalDestino->id,
        ]);

        $this->assertNotNull($item->fresh());
    }

    public function test_mesmo_animal_origem_nao_pode_aparecer_duas_vezes_na_mesma_transferencia(): void
    {
        $origem = Fazenda::create(['nome' => 'A']);
        $destino = Fazenda::create(['nome' => 'B']);
        $transferencia = TransferenciaFazenda::create([
            'fazenda_origem_id' => $origem->id,
            'fazenda_destino_id' => $destino->id,
            'chave_idempotencia' => 'chave-1',
            'data_transferencia' => now(),
        ]);
        $animalOrigem = Animal::create(['fazenda_id' => $origem->id, 'custo_aquisicao' => 1000, 'status' => 'transferido']);
        $animalDestino1 = Animal::create(['fazenda_id' => $destino->id, 'custo_aquisicao' => 1000, 'status' => 'ativo']);
        $animalDestino2 = Animal::create(['fazenda_id' => $destino->id, 'custo_aquisicao' => 1000, 'status' => 'ativo']);

        TransferenciaAnimal::create([
            'transferencia_id' => $transferencia->id,
            'animal_origem_id' => $animalOrigem->id,
            'animal_destino_id' => $animalDestino1->id,
        ]);

        $this->expectException(QueryException::class);
        TransferenciaAnimal::create([
            'transferencia_id' => $transferencia->id,
            'animal_origem_id' => $animalOrigem->id,
            'animal_destino_id' => $animalDestino2->id,
        ]);
    }

    /** INV-052 — diferente de vendido/morto, transferido é terminal de verdade (sem mecanismo de correção nesta rodada). */
    public function test_animal_transferido_e_terminal_nunca_aceita_novo_update(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $animal = Animal::create(['fazenda_id' => $fazenda->id, 'custo_aquisicao' => 1000, 'status' => 'transferido']);

        $this->expectException(LogicException::class);
        $animal->update(['status' => 'ativo']);
    }
}
