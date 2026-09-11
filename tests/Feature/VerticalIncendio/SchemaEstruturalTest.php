<?php

namespace Tests\Feature\VerticalIncendio;

use App\Models\Fazenda;
use App\Models\Incendio;
use App\Models\Piquete;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer IncendioService —
 * SCHEMA-CONTRATO-INCENDIO.md. Mesma filosofia dos 19 verticais
 * anteriores: testa que o schema em si (migrations + guards de Model) só
 * permite os estados que o contrato descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_incendio_e_imutavel_depois_de_registrado(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $piquete = Piquete::create(['fazenda_id' => $fazenda->id, 'nome' => 'Piquete 1', 'dias_descanso' => 20]);
        $incendio = Incendio::create([
            'fazenda_id' => $fazenda->id, 'piquete_id' => $piquete->id,
            'data_incendio' => '2026-01-01 14:00:00', 'chave_idempotencia' => 'x',
        ]);

        $this->assertThrows(
            fn () => $incendio->update(['data_incendio' => '2026-01-02 14:00:00']),
            LogicException::class
        );
    }

    public function test_incendio_e_unico_por_fazenda_e_chave_idempotencia(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $piquete = Piquete::create(['fazenda_id' => $fazenda->id, 'nome' => 'Piquete 1', 'dias_descanso' => 20]);
        Incendio::create([
            'fazenda_id' => $fazenda->id, 'piquete_id' => $piquete->id,
            'data_incendio' => '2026-01-01 14:00:00', 'chave_idempotencia' => 'mesma-chave',
        ]);

        $this->assertThrows(
            fn () => Incendio::create([
                'fazenda_id' => $fazenda->id, 'piquete_id' => $piquete->id,
                'data_incendio' => '2026-01-02 14:00:00', 'chave_idempotencia' => 'mesma-chave',
            ]),
            QueryException::class
        );
    }

    /** Sem UNIQUE(piquete_id, data_incendio) — reincidência é um fato real possível. */
    public function test_mesmo_piquete_pode_ter_dois_incendios_registrados(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $piquete = Piquete::create(['fazenda_id' => $fazenda->id, 'nome' => 'Piquete 1', 'dias_descanso' => 20]);
        Incendio::create([
            'fazenda_id' => $fazenda->id, 'piquete_id' => $piquete->id,
            'data_incendio' => '2026-01-01 14:00:00', 'chave_idempotencia' => 'primeiro',
        ]);
        Incendio::create([
            'fazenda_id' => $fazenda->id, 'piquete_id' => $piquete->id,
            'data_incendio' => '2026-06-01 14:00:00', 'chave_idempotencia' => 'segundo',
        ]);

        $this->assertSame(2, Incendio::where('piquete_id', $piquete->id)->count());
    }

    public function test_data_incendio_preserva_hora(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $piquete = Piquete::create(['fazenda_id' => $fazenda->id, 'nome' => 'Piquete 1', 'dias_descanso' => 20]);
        $incendio = Incendio::create([
            'fazenda_id' => $fazenda->id, 'piquete_id' => $piquete->id,
            'data_incendio' => '2026-03-05 14:30:00', 'chave_idempotencia' => 'x',
        ]);

        $this->assertSame('2026-03-05 14:30:00', $incendio->data_incendio->format('Y-m-d H:i:s'));
    }

    /** VERTICAL-INCENDIO.md §3 — nenhum efeito sobre o Piquete. */
    public function test_registrar_incendio_nao_altera_nenhum_campo_do_piquete(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $piquete = Piquete::create(['fazenda_id' => $fazenda->id, 'nome' => 'Piquete 1', 'dias_descanso' => 20]);
        Incendio::create([
            'fazenda_id' => $fazenda->id, 'piquete_id' => $piquete->id,
            'data_incendio' => '2026-01-01 14:00:00', 'chave_idempotencia' => 'x',
        ]);

        $piqueteRecarregado = Piquete::find($piquete->id);
        $this->assertSame('Piquete 1', $piqueteRecarregado->nome);
        $this->assertSame(20, $piqueteRecarregado->dias_descanso);
    }
}
