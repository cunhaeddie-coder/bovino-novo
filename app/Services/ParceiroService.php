<?php

namespace App\Services;

use App\Models\Parceiro;
use DomainException;

/**
 * SCHEMA-CONTRATO-PARCEIROS-COMISSAO.md — núcleo do Vertical 28
 * (Parceiros/Comissão), parte 1. Sem chave_idempotencia (mesmo precedente
 * de MotoristaService::cadastrar() — cadastro simples, sem consequência
 * financeira, não exige reenvio-detecção).
 */
class ParceiroService
{
    public function cadastrar(string $nome, ?string $crmCrc = null): Parceiro
    {
        if (trim($nome) === '') {
            throw new DomainException('nome não pode ser vazio.');
        }

        return Parceiro::create(['nome' => $nome, 'crm_crc' => $crmCrc]);
    }
}
