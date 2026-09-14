<?php

namespace App\Services;

use App\Models\EmbargoIbama;
use App\Models\Kyc;
use App\Models\Usuario;
use DomainException;

/**
 * VERTICAL-KYC.md / SCHEMA-CONTRATO-KYC.md — núcleo do Vertical 23. Kyc é
 * 1:1 com Fazenda, decidido sempre de forma síncrona (achado de LAB-FA-028:
 * sem etapa manual intermediária). Corte mínimo: checksum de CPF/CNPJ (INV-048)
 * + tabela local de embargos IBAMA — sem Receita Federal, IE, selfie/Didit,
 * sem chamada de rede real.
 */
class KycService
{
    public function submeter(int $usuarioId, int $fazendaId, string $documento, string $tipoDocumento): Kyc
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (! in_array($tipoDocumento, ['cpf', 'cnpj'], true)) {
            throw new DomainException("tipo_documento precisa ser 'cpf' ou 'cnpj' (recebido: {$tipoDocumento}).");
        }

        $documentoLimpo = preg_replace('/\D/', '', $documento) ?? '';
        if ($documentoLimpo === '') {
            throw new DomainException('documento não pode ser vazio.');
        }

        $checksumValido = $tipoDocumento === 'cpf' ? $this->cpfValido($documentoLimpo) : $this->cnpjValido($documentoLimpo);

        if (! $checksumValido) {
            return Kyc::updateOrCreate(
                ['fazenda_id' => $fazendaId],
                ['documento' => $documentoLimpo, 'tipo_documento' => $tipoDocumento, 'status' => 'reprovado', 'motivo_reprovacao' => 'documento_invalido', 'verificado_em' => now()]
            );
        }

        // INV-048 — só situacao=ativo reprova; cancelado nunca bloqueia.
        $embargado = EmbargoIbama::where('documento', $documentoLimpo)->where('situacao', 'ativo')->exists();

        return Kyc::updateOrCreate(
            ['fazenda_id' => $fazendaId],
            [
                'documento' => $documentoLimpo,
                'tipo_documento' => $tipoDocumento,
                'status' => $embargado ? 'reprovado' : 'aprovado',
                'motivo_reprovacao' => $embargado ? 'embargo_ibama' : null,
                'verificado_em' => now(),
            ]
        );
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
