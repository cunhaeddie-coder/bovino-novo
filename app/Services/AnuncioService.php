<?php

namespace App\Services;

use App\Models\Anuncio;
use App\Models\Fazenda;
use App\Models\Kyc;
use App\Models\Usuario;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * VERTICAL-MARKETPLACE.md §10 / SCHEMA-CONTRATO-MARKETPLACE.md §10 —
 * Marketplace (Vertical 4, já fechado) nunca teve Service próprio pra
 * Anúncio, só Anuncio::create() direto. Reaberto pelo Vertical 23 (KYC):
 * publicar() é a primeira camada de autorização que a criação de um
 * Anúncio ganha — exige Kyc.status=aprovado do Titular da Fazenda
 * vendedora (INV-046). SCHEMA-CONTRATO-TITULAR.md §4 (reabertura):
 * checagem passa a resolver via fazenda.titular_id, nunca mais direto por
 * fazenda_id.
 */
class AnuncioService
{
    public function publicar(int $usuarioId, int $fazendaId, float $precoTotal, array $animalIds): Anuncio
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);
        $this->garantirKycAprovado($fazendaId);

        if ($precoTotal <= 0) {
            throw new DomainException("publicar exige preco_total positivo (recebido: {$precoTotal}).");
        }

        if (empty($animalIds)) {
            throw new DomainException('publicar exige ao menos 1 animal.');
        }

        return DB::transaction(function () use ($fazendaId, $precoTotal, $animalIds) {
            $anuncio = Anuncio::create([
                'fazenda_id' => $fazendaId,
                'preco_total' => $precoTotal,
                'status' => 'ativo',
                'publicado_em' => now(),
            ]);

            $anuncio->animais()->attach($animalIds);

            return $anuncio->fresh();
        });
    }

    // INV-046 — nunca satisfeito por temRelacaoComFazenda; o Titular da
    // Fazenda vendedora precisa ter Kyc aprovado antes de publicar. Sem
    // Titular vinculado, falha direto — nunca chega a consultar Kyc.
    private function garantirKycAprovado(int $fazendaId): void
    {
        $fazenda = Fazenda::findOrFail($fazendaId);
        if ($fazenda->titular_id === null) {
            throw new DomainException("Operação recusada: Fazenda {$fazendaId} sem Titular vinculado — não pode publicar Anúncio.");
        }

        $aprovado = Kyc::where('titular_id', $fazenda->titular_id)->where('status', 'aprovado')->exists();
        if (! $aprovado) {
            throw new DomainException("Operação recusada: Fazenda {$fazendaId} não tem KYC aprovado — não pode publicar Anúncio.");
        }
    }

    private function garantirRelacaoComFazenda(int $usuarioId, int $fazendaId): void
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($fazendaId)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com a Fazenda {$fazendaId}.");
        }
    }
}
