<?php

namespace App\Console\Commands;

use App\Services\OutboxService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

// OBSERVABILIDADE-MINIMA-VENDA.md — imprime os sinais do corte mínimo.
// Formato de saída (texto legível) é decisão de implementação, não da
// especificação; nenhum formato de métrica (Prometheus etc.) foi decidido.
#[Signature('eventos:status')]
#[Description('Sinais mínimos de observabilidade do Outbox (OBSERVABILIDADE-MINIMA-VENDA.md)')]
class EventosStatus extends Command
{
    public function handle(OutboxService $outbox): int
    {
        $status = $outbox->status();

        $this->line('outbox_pending: '.$status['outbox_pending']);
        $this->line('outbox_oldest_pending_age_seconds: '.($status['outbox_oldest_pending_age_seconds'] ?? '—'));
        $this->line('outbox_processed_total: '.$status['outbox_processed_total']);
        $this->line('outbox_last_success_at: '.($status['outbox_last_success_at'] ?? '—'));
        $this->line('scanner_last_run_at: '.($status['scanner_last_run_at'] ?? '— (nunca rodou)'));

        return self::SUCCESS;
    }
}
