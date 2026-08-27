<?php

namespace App\Console\Commands;

use App\Models\ObservabilidadeVarredura;
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
        // OBSERVABILIDADE-MINIMA-VENDA.md §2 — registra que a varredura RODOU,
        // sucesso ou não. É esse sinal, não a contagem de pendentes, que
        // distingue "sem trabalho" de "a varredura parou de executar".
        try {
            $processados = $outbox->processarPendentes();
            $this->info("Eventos processados: {$processados->count()}");

            return self::SUCCESS;
        } finally {
            ObservabilidadeVarredura::registrarExecucao();
        }
    }
}
