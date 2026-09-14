<?php

namespace App\Services;

use App\Models\Motorista;
use App\Models\Usuario;
use DomainException;

/**
 * VERTICAL-FRETE-LOGISTICA.md §3 — Motorista nasce sempre pendente. Só um
 * Usuario com eh_administrador=true aprova/reprova — critérios reais de
 * aprovação (propriedade do caminhão, referências) ainda não definidos pelo
 * produtor, decisão humana, sem regra automatizada aqui (§9).
 */
class MotoristaService
{
    public function cadastrar(int $usuarioId, string $documento, ?int $fazendaId = null): Motorista
    {
        if (trim($documento) === '') {
            throw new DomainException('documento não pode ser vazio.');
        }

        return Motorista::create([
            'usuario_id' => $usuarioId,
            'fazenda_id' => $fazendaId,
            'documento' => $documento,
            'status' => 'pendente',
        ]);
    }

    public function aprovar(int $administradorUsuarioId, int $motoristaId): Motorista
    {
        return $this->definirStatus($administradorUsuarioId, $motoristaId, 'aprovado');
    }

    public function reprovar(int $administradorUsuarioId, int $motoristaId): Motorista
    {
        return $this->definirStatus($administradorUsuarioId, $motoristaId, 'reprovado');
    }

    private function definirStatus(int $administradorUsuarioId, int $motoristaId, string $status): Motorista
    {
        $this->garantirAdministrador($administradorUsuarioId);

        $motorista = Motorista::find($motoristaId);
        if (! $motorista) {
            throw new DomainException("Motorista #{$motoristaId} não encontrado.");
        }

        $motorista->update([
            'status' => $status,
            'aprovado_em' => now(),
            'aprovado_por' => $administradorUsuarioId,
        ]);

        return $motorista->fresh();
    }

    private function garantirAdministrador(int $usuarioId): void
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->eh_administrador) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} não é administrador.");
        }
    }
}
