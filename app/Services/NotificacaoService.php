<?php

namespace App\Services;

use App\Models\ConversaSuporte;
use App\Models\EventoDominio;
use App\Models\Notificacao;
use App\Models\Sugestao;
use Illuminate\Database\QueryException;

/**
 * SCHEMA-CONTRATO-SUPORTE-SUGESTOES-ADMIN.md §4 — chamado só por
 * OutboxService::processar(). Idempotência sob reprocessamento do mesmo
 * evento via UNIQUE(sugestao_id, tipo)/UNIQUE(conversa_suporte_id, tipo) +
 * catch(QueryException), mesmo padrão de LancamentoService::
 * processarLiquidacao() (Vertical 27).
 */
class NotificacaoService
{
    public function processarSugestaoCriada(EventoDominio $evento): void
    {
        $sugestao = Sugestao::find($evento->payload['sugestao_id']);
        if (! $sugestao) {
            return;
        }

        $this->criarIgnorandoDuplicata([
            'usuario_id' => null,
            'para_administrador' => true,
            'tipo' => 'sugestao_criada',
            'mensagem' => "Nova sugestão da Fazenda #{$sugestao->fazenda_id}: {$sugestao->mensagem}",
            'sugestao_id' => $sugestao->id,
        ]);
    }

    public function processarSugestaoRespondida(EventoDominio $evento): void
    {
        $sugestao = Sugestao::find($evento->payload['sugestao_id']);
        if (! $sugestao) {
            return;
        }

        $this->criarIgnorandoDuplicata([
            'usuario_id' => $sugestao->usuario_id,
            'para_administrador' => false,
            'tipo' => 'sugestao_respondida',
            'mensagem' => "Sua sugestão foi respondida: {$sugestao->resposta}",
            'sugestao_id' => $sugestao->id,
        ]);
    }

    public function processarConversaSuporteAberta(EventoDominio $evento): void
    {
        $conversa = ConversaSuporte::find($evento->payload['conversa_suporte_id']);
        if (! $conversa) {
            return;
        }

        $this->criarIgnorandoDuplicata([
            'usuario_id' => null,
            'para_administrador' => true,
            'tipo' => 'conversa_suporte_aberta',
            'mensagem' => "Nova conversa de suporte da Fazenda #{$conversa->fazenda_id}: {$conversa->mensagem}",
            'conversa_suporte_id' => $conversa->id,
        ]);
    }

    public function processarConversaSuporteRespondida(EventoDominio $evento): void
    {
        $conversa = ConversaSuporte::find($evento->payload['conversa_suporte_id']);
        if (! $conversa) {
            return;
        }

        $this->criarIgnorandoDuplicata([
            'usuario_id' => $conversa->usuario_id,
            'para_administrador' => false,
            'tipo' => 'conversa_suporte_respondida',
            'mensagem' => "Sua conversa de suporte foi respondida: {$conversa->resposta}",
            'conversa_suporte_id' => $conversa->id,
        ]);
    }

    private function criarIgnorandoDuplicata(array $dados): void
    {
        try {
            Notificacao::create($dados);
        } catch (QueryException $e) {
            if (! $this->violacaoDeUnicidade($e)) {
                throw $e;
            }
            // Reprocessamento do mesmo evento (varredura + fila disputando
            // o mesmo EventoDominio) — a Notificacao já existe, nada a fazer.
        }
    }

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
