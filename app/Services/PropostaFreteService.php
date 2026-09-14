<?php

namespace App\Services;

use App\Models\Motorista;
use App\Models\OrdemFrete;
use App\Models\PropostaFrete;
use App\Models\Usuario;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * VERTICAL-FRETE-LOGISTICA.md §3/§4 — lado leilão do Vertical 22. darLance()
 * exige Motorista aprovado (INV-042). aceitarProposta() converge no mesmo
 * efeito financeiro de contratarDireto() (OrdemFreteService::
 * registrarEfeitosDeAceite).
 */
class PropostaFreteService
{
    public function darLance(int $usuarioId, int $ordemFreteId, float $valorProposto): PropostaFrete
    {
        if ($valorProposto <= 0) {
            throw new DomainException("darLance exige valor_proposto positivo (recebido: {$valorProposto}).");
        }

        return DB::transaction(function () use ($usuarioId, $ordemFreteId, $valorProposto) {
            // INV-042 — lockForUpdate contra uma reprovação concorrente do
            // próprio Motorista.
            $motorista = Motorista::where('usuario_id', $usuarioId)->lockForUpdate()->first();
            if (! $motorista || $motorista->status !== 'aprovado') {
                throw new DomainException("Usuário {$usuarioId} não é um Motorista aprovado — não pode dar lance.");
            }

            $ordem = OrdemFrete::find($ordemFreteId);
            if (! $ordem) {
                throw new DomainException("Ordem de Frete #{$ordemFreteId} não encontrada.");
            }
            if ($ordem->status !== 'aguardando_lance') {
                throw new DomainException("Ordem de Frete #{$ordemFreteId} não aceita lances (status atual: {$ordem->status}).");
            }

            return PropostaFrete::create([
                'ordem_frete_id' => $ordemFreteId,
                'motorista_id' => $motorista->id,
                'valor_proposto' => $valorProposto,
                'status' => 'pendente',
            ]);
        });
    }

    public function aceitarProposta(int $usuarioId, int $ordemFreteId, int $propostaId): array
    {
        $ordemInicial = OrdemFrete::find($ordemFreteId);
        if (! $ordemInicial) {
            throw new DomainException("Ordem de Frete #{$ordemFreteId} não encontrada.");
        }

        $this->garantirRelacaoComFazenda($usuarioId, $ordemInicial->fazenda_id);

        return DB::transaction(function () use ($ordemFreteId, $propostaId) {
            // Mesma defesa que protege contra 2 aceites concorrentes ou
            // aceite concorrente com cancelamento (categoria já provada em
            // Marketplace, Ataque O).
            $ordem = OrdemFrete::lockForUpdate()->findOrFail($ordemFreteId);

            if ($ordem->status !== 'aguardando_lance') {
                throw new DomainException("Ordem de Frete #{$ordemFreteId} só aceita proposta a partir de status=aguardando_lance (atual: {$ordem->status}).");
            }

            $proposta = PropostaFrete::where('id', $propostaId)->where('ordem_frete_id', $ordemFreteId)->where('status', 'pendente')->first();
            if (! $proposta) {
                throw new DomainException("Proposta #{$propostaId} não encontrada, não pertence a esta Ordem, ou já não está pendente.");
            }

            $ordem->update([
                'status' => 'aceita',
                'motorista_id' => $proposta->motorista_id,
                'valor_frete' => $proposta->valor_proposto,
                'aceita_em' => now(),
            ]);

            $proposta->update(['status' => 'aceita']);
            PropostaFrete::where('ordem_frete_id', $ordemFreteId)->where('id', '!=', $propostaId)->where('status', 'pendente')->update(['status' => 'recusada']);

            app(OrdemFreteService::class)->registrarEfeitosDeAceite($ordem->fresh());

            return ['ordem_frete' => $ordem->fresh(), 'proposta' => $proposta->fresh()];
        });
    }

    private function garantirRelacaoComFazenda(int $usuarioId, int $fazendaId): void
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($fazendaId)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com a Fazenda {$fazendaId}.");
        }
    }
}
