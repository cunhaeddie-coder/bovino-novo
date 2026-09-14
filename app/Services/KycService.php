<?php

namespace App\Services;

use App\Models\EmbargoIbama;
use App\Models\Fazenda;
use App\Models\Kyc;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;

/**
 * VERTICAL-KYC.md / SCHEMA-CONTRATO-KYC.md — núcleo do Vertical 23,
 * reaberto pelo Vertical 25 (Titular): Kyc é 1:1 com Titular, não mais com
 * Fazenda. submeter() não recebe mais documento/tipoDocumento — isso agora
 * vive só em Titular, declarado por TitularService::vincular() antes.
 * Decidido sempre de forma síncrona (achado de LAB-FA-028: sem etapa
 * manual intermediária). Corte mínimo: checksum de CPF/CNPJ (INV-048) +
 * tabela local de embargos IBAMA — sem Receita Federal, IE, selfie/Didit,
 * sem chamada de rede real.
 */
class KycService
{
    public function submeter(int $usuarioId, int $fazendaId): Kyc
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        $fazenda = Fazenda::with('titular')->findOrFail($fazendaId);
        if ($fazenda->titular_id === null) {
            throw new DomainException("Fazenda {$fazendaId} sem Titular vinculado — vincule antes de submeter KYC.");
        }

        $titular = $fazenda->titular;
        $checksumValido = $titular->tipo_documento === 'cpf'
            ? $this->cpfValido($titular->documento)
            : $this->cnpjValido($titular->documento);

        if (! $checksumValido) {
            return $this->upsertKyc($titular->id, [
                'status' => 'reprovado', 'motivo_reprovacao' => 'documento_invalido', 'verificado_em' => now(),
            ]);
        }

        // INV-048 — só situacao=ativo reprova; cancelado nunca bloqueia.
        $embargado = EmbargoIbama::where('documento', $titular->documento)->where('situacao', 'ativo')->exists();

        return $this->upsertKyc($titular->id, [
            'status' => $embargado ? 'reprovado' : 'aprovado',
            'motivo_reprovacao' => $embargado ? 'embargo_ibama' : null,
            'verificado_em' => now(),
        ]);
    }

    // Achado do Spike 007 Extensão 24 (14/09/2026): Kyc::updateOrCreate()
    // sozinho é um SELECT-então-INSERT/UPDATE sem lock — 2 submeter()
    // concorrentes pro mesmo Titular sem Kyc ainda podiam, em teoria, os
    // dois tentar INSERT, e o perdedor bateria em UNIQUE(titular_id) sem
    // captura. Corrigido por consistência com o padrão já estabelecido
    // (violacaoDeUnicidade + retry), nunca por suposição de domínio novo.
    private function upsertKyc(int $titularId, array $valores): Kyc
    {
        try {
            return Kyc::updateOrCreate(['titular_id' => $titularId], $valores);
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                $kyc = Kyc::where('titular_id', $titularId)->firstOrFail();
                $kyc->update($valores);

                return $kyc->fresh();
            }
            throw $e;
        }
    }

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }

    // Algoritmo público padrão (módulo 11) — decisão técnica, não domínio
    // (SCHEMA-CONTRATO-KYC.md, cabeçalho).
    private function cpfValido(string $cpf): bool
    {
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        for ($posicao = 9; $posicao <= 10; $posicao++) {
            $soma = 0;
            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $cpf[$i] * (($posicao + 1) - $i);
            }
            $resto = $soma % 11;
            $digitoEsperado = $resto < 2 ? 0 : 11 - $resto;
            if ((int) $cpf[$posicao] !== $digitoEsperado) {
                return false;
            }
        }

        return true;
    }

    private function cnpjValido(string $cnpj): bool
    {
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj) === 1) {
            return false;
        }

        $pesosPrimeiroDigito = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $pesosSegundoDigito = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        foreach ([$pesosPrimeiroDigito, $pesosSegundoDigito] as $pesos) {
            $posicao = count($pesos);
            $soma = 0;
            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $cnpj[$i] * $pesos[$i];
            }
            $resto = $soma % 11;
            $digitoEsperado = $resto < 2 ? 0 : 11 - $resto;
            if ((int) $cnpj[$posicao] !== $digitoEsperado) {
                return false;
            }
        }

        return true;
    }

    private function garantirRelacaoComFazenda(int $usuarioId, int $fazendaId): void
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($fazendaId)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com a Fazenda {$fazendaId}.");
        }
    }
}
