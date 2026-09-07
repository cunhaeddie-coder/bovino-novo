<?php

namespace Tests\Feature\VerticalNascimento;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Nascimento;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer NascimentoService —
 * SCHEMA-CONTRATO-NASCIMENTO.md. Mesma filosofia dos 8 verticais anteriores:
 * testa que o schema em si (migrations + guards de Model) só permite os
 * estados que o contrato descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_nascimento_e_imutavel_depois_de_registrado(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $nascimento = Nascimento::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1, 2],
            'data_nascimento' => '2026-01-01 10:00:00', 'chave_idempotencia' => 'x',
        ]);

        $this->assertThrows(
            fn () => $nascimento->update(['animal_ids' => [1, 2, 3]]),
            LogicException::class
        );
    }

    public function test_nascimento_e_unico_por_fazenda_e_chave_idempotencia(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        Nascimento::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1],
            'data_nascimento' => '2026-01-01 10:00:00', 'chave_idempotencia' => 'mesma-chave',
        ]);

        $this->assertThrows(
            fn () => Nascimento::create([
                'fazenda_id' => $fazenda->id, 'animal_ids' => [2],
                'data_nascimento' => '2026-01-02 10:00:00', 'chave_idempotencia' => 'mesma-chave',
            ]),
            QueryException::class
        );
    }

    public function test_data_nascimento_preserva_hora(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $nascimento = Nascimento::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1],
            'data_nascimento' => '2026-03-05 06:30:00', 'chave_idempotencia' => 'x',
        ]);

        $this->assertSame('2026-03-05 06:30:00', $nascimento->data_nascimento->format('Y-m-d H:i:s'));
    }

    public function test_animal_aceita_tipo_origem_mae_id_e_peso_nascimento(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $mae = Animal::create(['fazenda_id' => $fazenda->id, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo']);
        $filhote = Animal::create([
            'fazenda_id' => $fazenda->id, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo',
            'tipo_origem' => 'nascido_na_fazenda', 'mae_id' => $mae->id, 'peso_nascimento' => 32.5,
        ]);

        $this->assertSame('nascido_na_fazenda', $filhote->tipo_origem);
        $this->assertSame($mae->id, $filhote->mae_id);
        $this->assertEqualsWithDelta(32.5, (float) $filhote->peso_nascimento, 0.01);
    }

    public function test_animal_aceita_nascimento_em_lote_sem_mae_conhecida(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $filhote = Animal::create([
            'fazenda_id' => $fazenda->id, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo',
            'tipo_origem' => 'nascido_na_fazenda',
        ]);

        $this->assertNull($filhote->mae_id);
        $this->assertNull($filhote->peso_nascimento);
    }

    /** Guard pré-existente do Vertical Compra — custo_aquisicao=0 (não NULL) satisfaz "exatamente um entre lote_id/custo_aquisicao". */
    public function test_animal_nascido_ainda_respeita_guard_lote_id_xor_custo_aquisicao(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);

        $this->assertThrows(
            fn () => Animal::create([
                'fazenda_id' => $fazenda->id, 'lote_id' => null, 'custo_aquisicao' => null, 'status' => 'ativo',
                'tipo_origem' => 'nascido_na_fazenda',
            ]),
            LogicException::class
        );
    }
}
