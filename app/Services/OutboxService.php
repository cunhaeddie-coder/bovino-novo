<?php

namespace App\Services;

use App\Models\EventoDominio;
use Illuminate\Support\Collection;

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
        $evento->update(['status_consequencia' => 'concluido']);

        return $evento;
    }
}
