<?php

namespace Tests\Feature\VerticalMorte;

use App\Models\Fazenda;
use App\Models\Morte;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer MorteService — SCHEMA-CONTRATO-MORTE.md.
 * Mesma filosofia dos 6 verticais anteriores: testa que o schema em si
 * (migrations + guards de Model) só permite os estados que o contrato
 * descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_morte_e_imutavel_depois_de_registrada(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $morte = Morte::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1, 2], 'causa' => 'doença',
            'data_morte' => '2026-01-01 10:00:00', 'chave_idempotencia' => 'x',
        ]);

        $this->assertThrows(
            fn () => $morte->update(['causa' => 'acidente']),
            LogicException::class
        );
    }

    public function test_morte_e_unica_por_fazenda_e_chave_idempotencia(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        Morte::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1], 'causa' => 'acidente',
            'data_morte' => '2026-01-01 10:00:00', 'chave_idempotencia' => 'mesma-chave',
        ]);

        $this->assertThrows(
            fn () => Morte::create([
                'fazenda_id' => $fazenda->id, 'animal_ids' => [2], 'causa' => 'doença',
                'data_morte' => '2026-01-02 10:00:00', 'chave_idempotencia' => 'mesma-chave',
            ]),
            QueryException::class
        );
    }

    public function test_data_morte_preserva_hora(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $morte = Morte::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1], 'causa' => 'desconhecida',
            'data_morte' => '2026-03-05 06:30:00', 'chave_idempotencia' => 'x',
        ]);

        $this->assertSame('2026-03-05 06:30:00', $morte->data_morte->format('Y-m-d H:i:s'));
    }

    public function test_causa_desconhecida_e_valor_valido(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $morte = Morte::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1], 'causa' => 'desconhecida',
            'data_morte' => '2026-01-01 10:00:00', 'chave_idempotencia' => 'x',
        ]);

        $this->assertSame('desconhecida', $morte->causa);
    }
}
