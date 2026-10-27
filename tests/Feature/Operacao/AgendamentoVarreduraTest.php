<?php

namespace Tests\Feature\Operacao;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * INV-027 só se sustenta se eventos:processar rodar sem ninguém chamar —
 * até a Inversão (26/10/2026) o comando existia, mas nada o agendava.
 */
class AgendamentoVarreduraTest extends TestCase
{
    public function test_varredura_do_outbox_esta_agendada_a_cada_minuto_sem_sobreposicao(): void
    {
        $eventos = collect(app(Schedule::class)->events())
            ->filter(fn (Event $evento) => str_contains($evento->command ?? '', 'eventos:processar'));

        $this->assertCount(1, $eventos);
        $this->assertSame('* * * * *', $eventos->first()->expression);
        $this->assertTrue($eventos->first()->withoutOverlapping);
    }
}
