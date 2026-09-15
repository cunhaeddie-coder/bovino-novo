<?php

namespace App\Services;

use App\Models\ResponsavelFiscal;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;

/**
 * SCHEMA-CONTRATO-CONFIGURACAO-FISCAL.md — núcleo do Vertical 29
 * (Configuração Fiscal), parte 1. `definirTaxa()` é idempotente por
 * natureza — sem chave_idempotencia, mesmo raciocínio já usado em
 * Fazenda.titular_id/Conta.tipo: chamar de novo com o mesmo valor produz o
 * mesmo estado, nunca duplica nada.
 *
 * Achado no caminho, corrigido antes de fechar o vertical: `updateOrCreate()`
 * puro (`firstOrNew`+`save()`) não é atômico — 2 chamadas concorrentes pra
 * uma Fazenda SEM ResponsavelFiscal ainda podem colidir no INSERT
 * (`fazenda_id` é a PK real da tabela, diferente do caso de Conta 'bovino'
 * no Vertical 27, que não tinha nenhuma coluna UNIQUE de verdade pra
 * proteger). Aqui existe, então o mesmo mecanismo catch(QueryException)+
 * retry já provado em Kyc/FazendaPerfil/Titular se aplica direto.
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

        $dados = ['usuario_id' => $usuarioId, 'taxa_venda_animal' => $taxaVendaAnimal, 'taxa_e_premissa' => false];

        $existente = ResponsavelFiscal::find($fazendaId);
        if ($existente) {
            $existente->update($dados);

            return $existente->fresh();
        }

        try {
            return ResponsavelFiscal::create(['fazenda_id' => $fazendaId] + $dados);
        } catch (QueryException $e) {
            if (! $this->violacaoDeUnicidade($e)) {
                throw $e;
            }

            $responsavel = ResponsavelFiscal::findOrFail($fazendaId);
            $responsavel->update($dados);

            return $responsavel->fresh();
        }
    }

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
