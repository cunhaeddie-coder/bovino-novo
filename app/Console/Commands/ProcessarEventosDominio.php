<?php

namespace App\Console\Commands;

use App\Services\OutboxService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

// A varredura periódica de MODELO-DE-EVENTOS.md — lê o banco, não a fila.
// Idempotente por construção: reprocessar um evento já 'concluido' não é
// chamado (processarPendentes só busca pendente/falhou_reprocessar).
#[Signature('eventos:processar')]
#[Description('Varredura periódica do Outbox — garantia de entrega das consequências desacopladas (INV-027)')]
class ProcessarEventosDominio extends Command
{
    public function handle(OutboxService $outbox): int
    {
        $processados = $outbox->processarPendentes();

        $this->info("Eventos processados: {$processados->count()}");

        return self::SUCCESS;
    }
}
