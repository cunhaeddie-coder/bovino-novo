<?php

namespace App\Services;

use App\Models\ConfiguracaoPlataforma;
use App\Models\Usuario;
use DomainException;

/**
 * VERTICAL-FRETE-LOGISTICA.md §3/§8 — comissão de frete é editável,
 * configuração global da plataforma. Edição só vale pra ordens aceitas
 * depois dela — nunca recalcula uma ComissaoPlataforma já congelada
 * (INV-043).
 */
class ConfiguracaoPlataformaService
{
    public function percentualComissaoFrete(): float
    {
        $config = ConfiguracaoPlataforma::where('chave', 'comissao_frete_percentual')->first();
        if (! $config) {
            throw new DomainException('Configuração comissao_frete_percentual não encontrada.');
        }

        return (float) $config->valor;
    }

    public function definirComissaoFrete(int $administradorUsuarioId, float $percentual): ConfiguracaoPlataforma
    {
        $usuario = Usuario::findOrFail($administradorUsuarioId);
        if (! $usuario->eh_administrador) {
            throw new DomainException("Operação recusada: usuário {$administradorUsuarioId} não é administrador.");
        }

        if ($percentual <= 0 || $percentual > 100) {
            throw new DomainException("percentual precisa estar entre 0 e 100 (recebido: {$percentual}).");
        }

        return ConfiguracaoPlataforma::updateOrCreate(
            ['chave' => 'comissao_frete_percentual'],
            ['valor' => (string) $percentual, 'atualizado_por' => $administradorUsuarioId]
        );
    }
}
