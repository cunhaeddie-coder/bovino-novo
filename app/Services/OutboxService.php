<?php

namespace App\Services;

use App\Models\EventoDominio;
use App\Models\ObservabilidadeVarredura;
use Illuminate\Support\Collection;
use Throwable;

/**
 * O consumidor da consequência desacoplada (MODELO-DE-EVENTOS.md, INV-027).
 * Roda tanto pela fila (acelerador) quanto pela varredura periódica
 * (garantia) — a mesma função, chamada dos dois caminhos.
 *
 * SCHEMA-CONTRATO-VENDA.md §12 marca este caminho como o mais provável de
 * abrir uma exceção acidental de fazenda_id, por rodar fora do ciclo de uma
 * requisição HTTP: cada evento só toca a própria linha, nunca agrega ou
 * cruza dados entre fazenda_id diferentes.
 *
 * Fiscal-documento/Auditoria/Notificação reais (VERTICAL-VENDA.md §4) ficam
 * fora do corte mínimo do vertical 1 — aqui só o mecanismo de entrega
 * garantida e idempotente é provado, não o conteúdo de cada consequência.
 *
 * SCHEMA-CONTRATO-CARTEIRA.md §4 — 1º consumidor real com efeito colateral
 * de negócio (`forma_pagamento_liquidada` → Lançamento). Isolamento por
 * evento: uma falha marca só aquele evento `falhou_reprocessar` (coluna já
 * prevista desde o início, nunca exercida até agora), nunca aborta o lote
 * inteiro — os outros eventos pendentes continuam sendo processados.
 *
 * SCHEMA-CONTRATO-SUPORTE-SUGESTOES-ADMIN.md §4 — 1º consumidor fora do
 * eixo financeiro (`sugestao_criada`/`sugestao_respondida`/
 * `conversa_suporte_aberta`/`conversa_suporte_respondida` → Notificação),
 * primeira implementação real de `INV-015`.
 */
class OutboxService
{
    public function processarPendentes(int $limite = 50): Collection
    {
        $pendentes = EventoDominio::whereIn('status_consequencia', ['pendente', 'falhou_reprocessar'])
            ->orderBy('id')
            ->limit($limite)
            ->get();

        return $pendentes->map(fn (EventoDominio $evento) => $this->processar($evento));
    }

    public function processar(EventoDominio $evento): EventoDominio
    {
        try {
            match ($evento->tipo) {
                'forma_pagamento_liquidada' => app(LancamentoService::class)->processarLiquidacao($evento),
                'sugestao_criada' => app(NotificacaoService::class)->processarSugestaoCriada($evento),
                'sugestao_respondida' => app(NotificacaoService::class)->processarSugestaoRespondida($evento),
                'conversa_suporte_aberta' => app(NotificacaoService::class)->processarConversaSuporteAberta($evento),
                'conversa_suporte_respondida' => app(NotificacaoService::class)->processarConversaSuporteRespondida($evento),
                default => null,
            };

            $evento->update(['status_consequencia' => 'concluido']);
        } catch (Throwable $e) {
            $evento->update(['status_consequencia' => 'falhou_reprocessar']);
        }

        return $evento;
    }

    /**
     * OBSERVABILIDADE-MINIMA-VENDA.md §2 — os quatro sinais do corte mínimo
     * mais scanner_last_run_at. `outbox_failed_total`/tentativas/erro ficam
     * de fora (§3): eventos_dominio não tem coluna pra contagem de
     * tentativas — só o status `falhou_reprocessar` (agora exercido pela
     * 1ª vez pelo consumidor da Carteira).
     */
    public function status(): array
    {
        $pendentes = EventoDominio::whereIn('status_consequencia', ['pendente', 'falhou_reprocessar']);

        $maisAntigoPendente = (clone $pendentes)->oldest('created_at')->first();

        return [
            'outbox_pending' => (clone $pendentes)->count(),
            'outbox_oldest_pending_age_seconds' => $maisAntigoPendente
                ? now()->diffInSeconds($maisAntigoPendente->created_at, absolute: true)
                : null,
            'outbox_processed_total' => EventoDominio::where('status_consequencia', 'concluido')->count(),
            'outbox_last_success_at' => EventoDominio::where('status_consequencia', 'concluido')->max('updated_at'),
            'scanner_last_run_at' => ObservabilidadeVarredura::ultimaExecucao()?->toDateTimeString(),
        ];
    }
}
