<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\MarcacaoCio;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;

/**
 * O núcleo do Vertical 17 (Marcação de Cio + Confirmação de Prenhez —
 * metade Marcação de Cio), nascido de
 * VERTICAL-MARCACAO-CIO-PRENHEZ.md, traduzindo
 * SCHEMA-CONTRATO-MARCACAO-CIO-PRENHEZ.md pra código real. Primeiro dos 2
 * pontos de ancoragem que faltavam no Histórico Reprodutivo (os outros 3 já
 * existem: Protocolo Reprodutivo, Confirmação de Prenhez — este mesmo
 * vertical —, Nascimento). INSERT puro, sem lockForUpdate() (mesma
 * categoria de risco de Nascimento/Produção Leiteira/Reclassificação,
 * hipótese a confirmar no spike).
 */
class MarcacaoCioService
{
    public function buscar(int $usuarioId, int $marcacaoId): ?MarcacaoCio
    {
        $marcacao = MarcacaoCio::find($marcacaoId);
        if (! $marcacao) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($marcacao->fazenda_id)) {
            return null;
        }

        return $marcacao;
    }

    public function registrar(int $usuarioId, int $fazendaId, int $vacaId, int $rufiaoId, string $dataMarcacao, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = MarcacaoCio::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'marcacao' => $existente];
        }

        if (! Animal::where('fazenda_id', $fazendaId)->where('id', $vacaId)->exists()) {
            throw new DomainException("Vaca #{$vacaId} não encontrada ou não pertence a esta Fazenda.");
        }

        if (! Animal::where('fazenda_id', $fazendaId)->where('id', $rufiaoId)->exists()) {
            throw new DomainException("Rufião #{$rufiaoId} não encontrado ou não pertence a esta Fazenda.");
        }

        try {
            $marcacao = MarcacaoCio::create([
                'fazenda_id' => $fazendaId,
                'vaca_id' => $vacaId,
                'rufiao_id' => $rufiaoId,
                'data_marcacao' => $dataMarcacao,
                'chave_idempotencia' => $chaveIdempotencia,
            ]);

            $this->registrarEvento('marcacao_cio_registrada', $fazendaId, $chaveIdempotencia, [
                'tipo' => 'marcacao_cio_registrada', 'marcacao_id' => $marcacao->id,
                'vaca_id' => $vacaId, 'rufiao_id' => $rufiaoId,
            ]);

            return ['reenvio_detectado' => false, 'marcacao' => $marcacao];
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'marcacao' => MarcacaoCio::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
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
