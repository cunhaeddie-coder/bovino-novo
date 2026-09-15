<?php

namespace App\Services;

use App\Models\ConversaSuporte;
use App\Models\EventoDominio;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;

/**
 * SCHEMA-CONTRATO-SUPORTE-SUGESTOES-ADMIN.md — núcleo do Vertical 30
 * (Suporte/Sugestões/Admin), parte 2. Mesmo shape de `SugestaoService` —
 * escalação/múltiplas mensagens ficam fora do corte mínimo (§7).
 */
class ConversaSuporteService
{
    public function buscar(int $usuarioId, int $conversaId): ?ConversaSuporte
    {
        $conversa = ConversaSuporte::find($conversaId);
        if (! $conversa) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($conversa->fazenda_id)) {
            return null;
        }

        return $conversa;
    }

    public function abrir(int $usuarioId, int $fazendaId, string $mensagem, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($mensagem) === '') {
            throw new DomainException('mensagem não pode ser vazia.');
        }

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = ConversaSuporte::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'conversa' => $existente];
        }

        try {
            $conversa = ConversaSuporte::create([
                'fazenda_id' => $fazendaId,
                'usuario_id' => $usuarioId,
                'mensagem' => $mensagem,
                'chave_idempotencia' => $chaveIdempotencia,
            ]);

            $this->registrarEvento('conversa_suporte_aberta', $fazendaId, $chaveIdempotencia, [
                'tipo' => 'conversa_suporte_aberta', 'conversa_suporte_id' => $conversa->id, 'fazenda_id' => $fazendaId,
            ]);

            return ['reenvio_detectado' => false, 'conversa' => $conversa];
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'conversa' => ConversaSuporte::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    public function responder(int $administradorUsuarioId, int $conversaId, string $resposta): ConversaSuporte
    {
        $this->garantirAdministrador($administradorUsuarioId);

        $conversa = ConversaSuporte::find($conversaId);
        if (! $conversa) {
            throw new DomainException("Conversa de Suporte #{$conversaId} não encontrada.");
        }

        if (trim($resposta) === '') {
            throw new DomainException('resposta não pode ser vazia.');
        }

        // INV-065 — idempotente, mesmo padrão de SugestaoService::responder().
        if ($conversa->resposta !== null) {
            return $conversa;
        }

        $conversa->update(['resposta' => $resposta, 'respondida_em' => now()]);

        $this->registrarEvento('conversa_suporte_respondida', $conversa->fazenda_id, "conversa-suporte-{$conversa->id}", [
            'tipo' => 'conversa_suporte_respondida', 'conversa_suporte_id' => $conversa->id, 'fazenda_id' => $conversa->fazenda_id,
        ]);

        return $conversa->fresh();
    }

    private function garantirRelacaoComFazenda(int $usuarioId, int $fazendaId): void
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($fazendaId)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com a Fazenda {$fazendaId}.");
        }
    }

    private function garantirAdministrador(int $usuarioId): void
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->eh_administrador) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} não é administrador.");
        }
    }

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

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
