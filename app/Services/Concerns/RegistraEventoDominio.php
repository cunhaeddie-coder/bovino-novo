<?php

namespace App\Services\Concerns;

use App\Models\EventoDominio;

/**
 * O evento de domínio gravado na mesma transação do fato (INV-027, outbox +
 * varredura): o processador de eventos lê os pendentes e aplica as
 * consequências desacopladas (notificação, carteira, auditoria).
 *
 * Era um método privado copiado em 25 Services (achado registrado no mapa
 * desde 15/09/2026; roteiro até a publicação, etapa 1, 27/10/2026). Um lugar
 * só: mudar o formato do evento muda pra todos.
 */
trait RegistraEventoDominio
{
    // A chave do evento é a do fato + ":evento" — única por (fazenda_id,
    // chave_idempotencia), então o mesmo fato nunca gera dois eventos.
    private function registrarEvento(string $tipo, int $fazendaId, string $chaveIdempotenciaDoFato, array $payload): EventoDominio
    {
        return EventoDominio::create([
            'tipo' => $tipo,
            'fazenda_id' => $fazendaId,
            'chave_idempotencia' => $chaveIdempotenciaDoFato.':evento',
            'payload' => $payload,
            'status_consequencia' => 'pendente',
        ]);
    }
}
