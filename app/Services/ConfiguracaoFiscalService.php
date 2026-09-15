<?php

namespace App\Services;

use App\Models\ResponsavelFiscal;
use App\Models\Usuario;
use DomainException;

/**
 * SCHEMA-CONTRATO-CONFIGURACAO-FISCAL.md — núcleo do Vertical 29
 * (Configuração Fiscal), parte 1. `definirTaxa()` é idempotente por
 * natureza (updateOrCreate) — sem chave_idempotencia, mesmo raciocínio já
 * usado em Fazenda.titular_id/Conta.tipo: chamar de novo com o mesmo valor
 * produz o mesmo estado, nunca duplica nada.
 */
class ConfiguracaoFiscalService
{
    public function definirTaxa(int $usuarioId, int $fazendaId, float $taxaVendaAnimal): ResponsavelFiscal
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($fazendaId)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com a Fazenda {$fazendaId}.");
        }

        if ($taxaVendaAnimal < 0 || $taxaVendaAnimal > 1) {
            throw new DomainException("taxaVendaAnimal precisa estar entre 0 e 1 (recebido: {$taxaVendaAnimal}).");
        }

        return ResponsavelFiscal::updateOrCreate(
            ['fazenda_id' => $fazendaId],
            ['usuario_id' => $usuarioId, 'taxa_venda_animal' => $taxaVendaAnimal, 'taxa_e_premissa' => false]
        );
    }
}
