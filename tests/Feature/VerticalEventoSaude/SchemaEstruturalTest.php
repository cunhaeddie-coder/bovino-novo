<?php

namespace Tests\Feature\VerticalEventoSaude;

use App\Models\ConsumoInsumo;
use App\Models\EventoSaude;
use App\Models\Fazenda;
use App\Models\Insumo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer EventoSaudeService —
 * SCHEMA-CONTRATO-EVENTO-SAUDE.md. Mesma filosofia dos 10 verticais
 * anteriores: testa que o schema em si (migrations + guards de Model) só
 * permite os estados que o contrato descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    private function criarConsumo(int $fazendaId): ConsumoInsumo
    {
        $insumo = Insumo::create(['fazenda_id' => $fazendaId, 'nome' => 'Vacina Aftosa', 'quantidade' => 100]);

        return ConsumoInsumo::create([
            'fazenda_id' => $fazendaId, 'insumo_id' => $insumo->id, 'quantidade' => 10,
            'data_consumo' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'consumo-x',
        ]);
    }

    public function test_evento_saude_e_imutavel_depois_de_registrado(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $consumo = $this->criarConsumo($fazenda->id);
        $evento = EventoSaude::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1, 2], 'descricao' => 'vacina aftosa',
            'consumo_insumo_id' => $consumo->id, 'data_aplicacao' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'x',
        ]);

        $this->assertThrows(
            fn () => $evento->update(['descricao' => 'outra coisa']),
            LogicException::class
        );
    }

    public function test_evento_saude_e_unico_por_fazenda_e_chave_idempotencia(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $consumo = $this->criarConsumo($fazenda->id);
        EventoSaude::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1], 'descricao' => 'vacina',
            'consumo_insumo_id' => $consumo->id, 'data_aplicacao' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'mesma-chave',
        ]);

        $this->assertThrows(
            fn () => EventoSaude::create([
                'fazenda_id' => $fazenda->id, 'animal_ids' => [2], 'descricao' => 'vermifugo',
                'consumo_insumo_id' => $consumo->id, 'data_aplicacao' => '2026-01-02 08:00:00', 'chave_idempotencia' => 'mesma-chave',
            ]),
            QueryException::class
        );
    }

    public function test_data_aplicacao_preserva_hora(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $consumo = $this->criarConsumo($fazenda->id);
        $evento = EventoSaude::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1], 'descricao' => 'vacina',
            'consumo_insumo_id' => $consumo->id, 'data_aplicacao' => '2026-03-05 06:30:00', 'chave_idempotencia' => 'x',
        ]);

        $this->assertSame('2026-03-05 06:30:00', $evento->data_aplicacao->format('Y-m-d H:i:s'));
    }
}
