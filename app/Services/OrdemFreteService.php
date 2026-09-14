<?php

namespace App\Services;

use App\Models\ComissaoPlataforma;
use App\Models\EventoDominio;
use App\Models\Motorista;
use App\Models\ObrigacaoFinanceira;
use App\Models\OrdemFrete;
use App\Models\PropostaFrete;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * VERTICAL-FRETE-LOGISTICA.md / SCHEMA-CONTRATO-FRETE-LOGISTICA.md — núcleo
 * do Vertical 22. Ordem de Frete nasce por leilão (solicitar → dar lance →
 * aceitar) ou contratação direta — os dois convergem no mesmo efeito
 * (registrarEfeitosDeAceite), reaproveitado por PropostaFreteService.
 */
class OrdemFreteService
{
    public function solicitar(int $usuarioId, int $fazendaId, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = OrdemFrete::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'ordem_frete' => $existente];
        }

        try {
            $ordem = OrdemFrete::create([
                'fazenda_id' => $fazendaId,
                'status' => 'aguardando_lance',
                'chave_idempotencia' => $chaveIdempotencia,
            ]);

            return ['reenvio_detectado' => false, 'ordem_frete' => $ordem];
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'ordem_frete' => OrdemFrete::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    public function contratarDireto(int $usuarioId, int $fazendaId, int $motoristaId, float $valorFrete, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($valorFrete <= 0) {
            throw new DomainException("contratarDireto exige valor_frete positivo (recebido: {$valorFrete}).");
        }

        if ($existente = OrdemFrete::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'ordem_frete' => $existente];
        }

        return DB::transaction(function () use ($fazendaId, $motoristaId, $valorFrete, $chaveIdempotencia) {
            // INV-042 — lockForUpdate contra uma reprovação concorrente do
            // mesmo Motorista (mesma defesa já provada desde o Ataque A).
            $motorista = Motorista::where('id', $motoristaId)->lockForUpdate()->first();
            if (! $motorista || $motorista->status !== 'aprovado') {
                throw new DomainException("Motorista #{$motoristaId} não está aprovado — não pode ser contratado diretamente.");
            }

            try {
                $ordem = OrdemFrete::create([
                    'fazenda_id' => $fazendaId,
                    'status' => 'aceita',
                    'motorista_id' => $motoristaId,
                    'valor_frete' => $valorFrete,
                    'chave_idempotencia' => $chaveIdempotencia,
                    'aceita_em' => now(),
                ]);
            } catch (QueryException $e) {
                if ($this->violacaoDeUnicidade($e)) {
                    return ['reenvio_detectado' => true, 'ordem_frete' => OrdemFrete::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
                }
                throw $e;
            }

            $this->registrarEfeitosDeAceite($ordem);

            return ['reenvio_detectado' => false, 'ordem_frete' => $ordem->fresh()];
        });
    }

    public function cancelar(int $usuarioId, int $ordemFreteId): array
    {
        $ordem = OrdemFrete::find($ordemFreteId);
        if (! $ordem) {
            throw new DomainException("Ordem de Frete #{$ordemFreteId} não encontrada.");
        }

        $this->garantirRelacaoComFazenda($usuarioId, $ordem->fazenda_id);

        return DB::transaction(function () use ($ordemFreteId) {
            // INV-044 — lockForUpdate contra um aceite concorrente da mesma
            // Ordem.
            $ordem = OrdemFrete::lockForUpdate()->findOrFail($ordemFreteId);

            if ($ordem->status !== 'aguardando_lance') {
                throw new DomainException("Ordem de Frete #{$ordemFreteId} só pode ser cancelada a partir de status=aguardando_lance (atual: {$ordem->status}).");
            }

            $ordem->update(['status' => 'cancelada', 'cancelada_em' => now()]);

            PropostaFrete::where('ordem_frete_id', $ordemFreteId)->where('status', 'pendente')->update(['status' => 'recusada']);

            return ['ordem_frete' => $ordem->fresh()];
        });
    }

    public function concluir(int $usuarioId, int $ordemFreteId): array
    {
        $ordem = OrdemFrete::find($ordemFreteId);
        if (! $ordem) {
            throw new DomainException("Ordem de Frete #{$ordemFreteId} não encontrada.");
        }

        $this->garantirRelacaoComFazenda($usuarioId, $ordem->fazenda_id);

        if ($ordem->status !== 'aceita') {
            throw new DomainException("Ordem de Frete #{$ordemFreteId} só pode ser concluída a partir de status=aceita (atual: {$ordem->status}).");
        }

        $ordem->update(['status' => 'concluida', 'concluida_em' => now()]);

        return ['ordem_frete' => $ordem->fresh()];
    }

    /**
     * SCHEMA-CONTRATO-FRETE-LOGISTICA.md §4 — mesmo efeito financeiro pra
     * leilão (aceitarProposta, PropostaFreteService) e contratação direta:
     * ObrigacaoFinanceira (a_pagar) + ComissaoPlataforma (percentual
     * congelado, INV-043) + evento outbox ordem_frete_aceita. Chamado só
     * depois que a OrdemFrete já está com motorista_id/valor_frete/status
     * gravados, dentro da mesma transação.
     */
    public function registrarEfeitosDeAceite(OrdemFrete $ordem): void
    {
        ObrigacaoFinanceira::create([
            'fazenda_id' => $ordem->fazenda_id,
            'ordem_frete_id' => $ordem->id,
            'direcao' => 'a_pagar',
            'valor' => $ordem->valor_frete,
        ]);

        $percentual = app(ConfiguracaoPlataformaService::class)->percentualComissaoFrete();
        ComissaoPlataforma::create([
            'ordem_frete_id' => $ordem->id,
            'valor_comissao' => round((float) $ordem->valor_frete * $percentual / 100, 2),
            'percentual_aplicado' => $percentual,
        ]);

        EventoDominio::create([
            'tipo' => 'ordem_frete_aceita',
            'fazenda_id' => $ordem->fazenda_id,
            'chave_idempotencia' => $ordem->chave_idempotencia.':evento',
            'payload' => [
                'ordem_frete_id' => $ordem->id,
                'motorista_id' => $ordem->motorista_id,
                'valor_frete' => (float) $ordem->valor_frete,
            ],
            'status_consequencia' => 'pendente',
        ]);
    }

    private function garantirRelacaoComFazenda(int $usuarioId, int $fazendaId): void
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($fazendaId)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com a Fazenda {$fazendaId}.");
        }
    }

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
