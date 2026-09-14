<?php

namespace App\Services;

use App\Models\Fazenda;
use App\Models\Titular;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;

/**
 * VERTICAL-TITULAR.md / SCHEMA-CONTRATO-TITULAR.md — núcleo do Vertical 25.
 * Vincular só declara quem é o titular jurídico da Fazenda — checksum e
 * verificação de identidade ficam inteiramente com KycService.
 */
class TitularService
{
    public function vincular(int $usuarioId, int $fazendaId, string $documento, string $tipoDocumento): Fazenda
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (! in_array($tipoDocumento, ['cpf', 'cnpj'], true)) {
            throw new DomainException("tipo_documento precisa ser 'cpf' ou 'cnpj' (recebido: {$tipoDocumento}).");
        }

        $documentoLimpo = preg_replace('/\D/', '', $documento) ?? '';
        if ($documentoLimpo === '') {
            throw new DomainException('documento não pode ser vazio.');
        }

        $titular = $this->buscarOuCriarTitular($documentoLimpo, $tipoDocumento);

        $fazenda = Fazenda::findOrFail($fazendaId);
        $fazenda->update(['titular_id' => $titular->id]); // substitui o anterior, sem histórico (VERTICAL-TITULAR.md §8)

        return $fazenda->fresh();
    }

    // Mesmo padrão de captura+retry já corrigido em KycService/
    // FazendaPerfilService (Extensões 24/25 do Spike 007) — firstOrCreate()
    // não é atômico, 2 Fazendas diferentes declarando o mesmo documento
    // pela primeira vez podem colidir em UNIQUE(documento).
    private function buscarOuCriarTitular(string $documento, string $tipoDocumento): Titular
    {
        try {
            return Titular::firstOrCreate(['documento' => $documento], ['tipo_documento' => $tipoDocumento]);
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return Titular::where('documento', $documento)->firstOrFail();
            }
            throw $e;
        }
    }

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }

    private function garantirRelacaoComFazenda(int $usuarioId, int $fazendaId): void
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($fazendaId)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com a Fazenda {$fazendaId}.");
        }
    }
}
