<?php

namespace App\Services;

use App\Models\EventoDominio;
use App\Models\Incendio;
use App\Models\Piquete;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;

/**
 * O núcleo do Vertical 20 (Incêndio), nascido de VERTICAL-INCENDIO.md,
 * traduzindo SCHEMA-CONTRATO-INCENDIO.md pra código real. Diferente de
 * todos os 19 verticais anteriores, nenhum LAB-FA/LAB-SA reproduz este
 * cenário no Atual — a única evidência é a confirmação direta do produtor
 * (PERGUNTAS-DOMINIO.md, pergunta 54). INSERT puro, sem lockForUpdate(),
 * mais simples até que RotacaoPastagemService (sem leitura de histórico —
 * aqui não há cálculo algum, só o registro do fato).
 */
class IncendioService
{
    public function buscar(int $usuarioId, int $incendioId): ?Incendio
    {
        $incendio = Incendio::find($incendioId);
        if (! $incendio) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($incendio->fazenda_id)) {
            return null;
        }

        return $incendio;
    }

    public function registrar(int $usuarioId, int $fazendaId, int $piqueteId, string $dataIncendio, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = Incendio::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'incendio' => $existente];
        }

        if (! Piquete::where('fazenda_id', $fazendaId)->where('id', $piqueteId)->exists()) {
            throw new DomainException("Piquete #{$piqueteId} não encontrado ou não pertence a esta Fazenda.");
        }

        try {
            $incendio = Incendio::create([
                'fazenda_id' => $fazendaId,
                'piquete_id' => $piqueteId,
                'data_incendio' => $dataIncendio,
                'chave_idempotencia' => $chaveIdempotencia,
            ]);

            $this->registrarEvento('incendio_registrado', $fazendaId, $chaveIdempotencia, [
                'tipo' => 'incendio_registrado', 'incendio_id' => $incendio->id,
                'piquete_id' => $piqueteId, 'data_incendio' => $dataIncendio,
            ]);

            return ['reenvio_detectado' => false, 'incendio' => $incendio];
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'incendio' => Incendio::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    private function garantirRelacaoComFazenda(int $usuarioId, int $fazendaId): void
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($fazendaId)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com a Fazenda {$fazendaId}.");
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
