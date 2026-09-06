<?php

namespace Tests\Feature\VerticalConsumoInsumo;

use App\Models\ConsumoInsumo;
use App\Models\Fazenda;
use App\Models\Insumo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer ConsumoInsumoService —
 * SCHEMA-CONTRATO-CONSUMO-INSUMO.md. Mesma filosofia dos 5 verticais
 * anteriores: testa que o schema em si (migrations + guards de Model) só
 * permite os estados que o contrato descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_consumo_insumo_e_imutavel_depois_de_criado(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $insumo = Insumo::create(['fazenda_id' => $fazenda->id, 'nome' => 'Sal', 'quantidade' => 100]);
        $consumo = ConsumoInsumo::create([
            'fazenda_id' => $fazenda->id, 'insumo_id' => $insumo->id, 'quantidade' => 10,
            'data_consumo' => '2026-01-01 10:00:00', 'chave_idempotencia' => 'x',
        ]);

        $this->assertThrows(
            fn () => $consumo->update(['quantidade' => 5]),
            LogicException::class
        );
    }

    public function test_consumo_insumo_e_unico_por_fazenda_e_chave_idempotencia(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $insumo = Insumo::create(['fazenda_id' => $fazenda->id, 'nome' => 'Sal', 'quantidade' => 100]);
        ConsumoInsumo::create([
            'fazenda_id' => $fazenda->id, 'insumo_id' => $insumo->id, 'quantidade' => 10,
            'data_consumo' => '2026-01-01 10:00:00', 'chave_idempotencia' => 'mesma-chave',
        ]);

        $this->assertThrows(
            fn () => ConsumoInsumo::create([
                'fazenda_id' => $fazenda->id, 'insumo_id' => $insumo->id, 'quantidade' => 5,
                'data_consumo' => '2026-01-02 10:00:00', 'chave_idempotencia' => 'mesma-chave',
            ]),
            QueryException::class
        );
    }

    public function test_data_consumo_preserva_hora(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $insumo = Insumo::create(['fazenda_id' => $fazenda->id, 'nome' => 'Sal', 'quantidade' => 100]);
        $consumo = ConsumoInsumo::create([
            'fazenda_id' => $fazenda->id, 'insumo_id' => $insumo->id, 'quantidade' => 10,
            'data_consumo' => '2026-03-05 08:15:00', 'chave_idempotencia' => 'x',
        ]);

        $this->assertSame('2026-03-05 08:15:00', $consumo->data_consumo->format('Y-m-d H:i:s'));
    }
}
