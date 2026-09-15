<?php

namespace App\Services;

use App\Models\EventoDominio;
use App\Models\Sugestao;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;

/**
 * SCHEMA-CONTRATO-SUPORTE-SUGESTOES-ADMIN.md — núcleo do Vertical 30
 * (Suporte/Sugestões/Admin), parte 1. `criar()`/`responder()` emitem
 * eventos que o `NotificacaoService` consome — primeiro vertical a
 * implementar `INV-015` de fato (a outra parte é avisada, não só a
 * confirmação manual já provada em Parceiros/Comissão).
 */
class SugestaoService
{
    public function buscar(int $usuarioId, int $sugestaoId): ?Sugestao
    {
        $sugestao = Sugestao::find($sugestaoId);
        if (! $sugestao) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($sugestao->fazenda_id)) {
            return null;
        }

        return $sugestao;
    }

    public function criar(int $usuarioId, int $fazendaId, string $mensagem, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($mensagem) === '') {
            throw new DomainException('mensagem não pode ser vazia.');
        }

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = Sugestao::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'sugestao' => $existente];
        }

        try {
            $sugestao = Sugestao::create([
                'fazenda_id' => $fazendaId,
                'usuario_id' => $usuarioId,
                'mensagem' => $mensagem,
                'chave_idempotencia' => $chaveIdempotencia,
            ]);

            $this->registrarEvento('sugestao_criada', $fazendaId, $chaveIdempotencia, [
                'tipo' => 'sugestao_criada', 'sugestao_id' => $sugestao->id, 'fazenda_id' => $fazendaId,
            ]);

            return ['reenvio_detectado' => false, 'sugestao' => $sugestao];
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'sugestao' => Sugestao::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    public function responder(int $administradorUsuarioId, int $sugestaoId, string $resposta): Sugestao
    {
        $this->garantirAdministrador($administradorUsuarioId);

        $sugestao = Sugestao::find($sugestaoId);
        if (! $sugestao) {
            throw new DomainException("Sugestão #{$sugestaoId} não encontrada.");
        }

        if (trim($resposta) === '') {
            throw new DomainException('resposta não pode ser vazia.');
        }

        // INV-065 — idempotente, mesmo padrão de liquidar()/confirmarConversao().
        if ($sugestao->resposta !== null) {
            return $sugestao;
        }

        $sugestao->update(['resposta' => $resposta, 'respondida_em' => now()]);

        $this->registrarEvento('sugestao_respondida', $sugestao->fazenda_id, "sugestao-{$sugestao->id}", [
            'tipo' => 'sugestao_respondida', 'sugestao_id' => $sugestao->id, 'fazenda_id' => $sugestao->fazenda_id,
        ]);

        return $sugestao->fresh();
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
