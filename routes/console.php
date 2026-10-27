<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// INV-027 / MODELO-DE-EVENTOS.md §4 — a varredura do Outbox precisa rodar
// sozinha; sem agendamento, nenhuma consequência desacoplada é entregue fora
// dos testes. MODELO-DE-EVENTOS.md deixa a frequência em aberto (decisão de
// operação): a cada minuto é a menor cadência do schedule:run, escolhida pra
// homologação (Inversão, 26/10/2026). withoutOverlapping: uma varredura
// lenta não se sobrepõe à seguinte.
Schedule::command('eventos:processar')->everyMinute()->withoutOverlapping();
