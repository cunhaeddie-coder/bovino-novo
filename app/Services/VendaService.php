<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Lote;
use App\Models\ResponsavelFiscal;
use App\Models\Usuario;
use App\Models\Venda;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O núcleo do Vertical 1 (Venda), nascido de VERTICAL-VENDA.md, traduzindo o
 * mecanismo já provado em bovino-lab/spikes/006-teste-dominio-vertical-venda
 * pra código real. Une: isolamento (INV-029), idempotência de reenvio
 * (INV-028), núcleo atômico (INV-020), outbox (INV-027).
 *
 * A checagem de autorização (SCHEMA-CONTRATO-VENDA.md §8) roda como
 * verificação explícita aqui, antes de qualquer query tocar vendas/animais —
 * Middleware/Policy podem envolver isto quando a camada HTTP existir.
 */
class VendaService
{
    public function buscar(int $usuarioId, int $vendaId): ?Venda
    {
        $venda = Venda::find($vendaId);
        if (! $venda) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($venda->fazenda_id)) {
            return null;
        }

        return $venda;
    }

    public function registrar(int $usuarioId, int $fazendaId, array $animalIds, float $valorBruto, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if ($existente = Venda::where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'venda' => $existente];
        }

        try {
            return DB::transaction(function () use ($fazendaId, $animalIds, $valorBruto, $chaveIdempotencia) {
                $animais = Animal::where('fazenda_id', $fazendaId)
                    ->whereIn('id', $animalIds)
                    ->where('status', 'ativo')
                    ->lockForUpdate()
                    ->get();

                if ($animais->count() !== count($animalIds)) {
                    throw new DomainException(
                        'Um ou mais animais pedidos não pertencem a esta Fazenda ou já não estão ativos — nenhum efeito parcial aplicado.'
                    );
                }

                $animais->each(fn (Animal $a) => $a->update(['status' => 'vendido', 'data_saida' => now()->toDateString()]));

                $idsVendidos = $animais->pluck('id')->values()->all();

                // INV-001 — recálculo do(s) lote(s) de origem, proporcional ao que saiu.
                $cpv = 0.0;
                foreach ($animais->groupBy('lote_id') as $loteId => $doLote) {
                    $lote = Lote::where('id', $loteId)->lockForUpdate()->firstOrFail();
                    $qtdSaida = $doLote->count();
                    $custoUnitario = $lote->custo_aquisicao / $lote->qtd_animais;
                    $cpvLote = round($custoUnitario * $qtdSaida, 2);
                    $cpv += $cpvLote;
                    $lote->update([
                        'qtd_animais' => $lote->qtd_animais - $qtdSaida,
                        'custo_aquisicao' => $lote->custo_aquisicao - $cpvLote,
                    ]);
                }
                $cpv = round($cpv, 2);

                [$deducaoFiscal, $ehPremissa] = $this->calcularDeducaoFiscal($fazendaId, $valorBruto);
                $receitaLiquida = round($valorBruto - $cpv - $deducaoFiscal, 2);

                $venda = Venda::create([
                    'fazenda_id' => $fazendaId,
                    'venda_original_id' => null,
                    'chave_idempotencia' => $chaveIdempotencia,
                    'animal_ids' => $idsVendidos,
                    'valor_bruto' => $valorBruto,
                    'cpv' => $cpv,
                    'deducao_fiscal' => $deducaoFiscal,
                    'fiscal_e_premissa' => $ehPremissa,
                    'receita_liquida' => $receitaLiquida,
                ]);

                $this->registrarEvento('venda_concluida', $fazendaId, $chaveIdempotencia, [
                    'tipo' => 'venda_concluida', 'venda_id' => $venda->id, 'fazenda_id' => $fazendaId,
                ]);

                return [
                    'reenvio_detectado' => false,
                    'venda' => $venda,
                    'ids_vendidos' => $idsVendidos,
                    'cpv' => $cpv,
                    'deducao_fiscal' => $deducaoFiscal,
                    'fiscal_e_premissa' => $ehPremissa,
                    'receita_liquida' => $receitaLiquida,
                ];
            });
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'venda' => Venda::where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    /**
     * Correção do fato Venda (VERTICAL-VENDA.md §3c / INV-026). NUNCA faz
     * UPDATE na venda original — cria um novo registro que a referencia.
     */
    public function corrigir(int $usuarioId, int $vendaOriginalId, array $novosAnimalIds, float $novoValorBruto, string $chaveIdempotencia): array
    {
        $original = $this->buscar($usuarioId, $vendaOriginalId);
        if (! $original) {
            throw new DomainException("Venda original #{$vendaOriginalId} não encontrada ou sem relação com a Fazenda do usuário {$usuarioId}.");
        }
        $fazendaId = $original->fazenda_id;

        if ($existente = Venda::where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'venda' => $existente];
        }

        $idsOriginais = $original->animal_ids;
        $idsQueSaem = array_values(array_diff($idsOriginais, $novosAnimalIds));

        try {
            return DB::transaction(function () use ($original, $fazendaId, $idsOriginais, $idsQueSaem, $novosAnimalIds, $novoValorBruto, $chaveIdempotencia, $vendaOriginalId) {
                $custoUnitarioOriginal = $original->cpv / count($idsOriginais);

                if ($idsQueSaem) {
                    $animaisQueVoltam = Animal::where('fazenda_id', $fazendaId)
                        ->whereIn('id', $idsQueSaem)
                        ->lockForUpdate()
                        ->get();

                    foreach ($animaisQueVoltam as $animal) {
                        $animal->update(['status' => 'ativo', 'data_saida' => null]);

                        $lote = Lote::where('id', $animal->lote_id)->lockForUpdate()->first();
                        if ($lote) {
                            $lote->update([
                                'qtd_animais' => $lote->qtd_animais + 1,
                                'custo_aquisicao' => $lote->custo_aquisicao + round($custoUnitarioOriginal, 2),
                            ]);
                        }
                    }
                }

                [$deducaoFiscal, $ehPremissa] = $this->calcularDeducaoFiscal($fazendaId, $novoValorBruto);
                $novoCpv = round($custoUnitarioOriginal * count($novosAnimalIds), 2);
                $novaReceitaLiquida = round($novoValorBruto - $novoCpv - $deducaoFiscal, 2);

                $correcao = Venda::create([
                    'fazenda_id' => $fazendaId,
                    'venda_original_id' => $vendaOriginalId,
                    'chave_idempotencia' => $chaveIdempotencia,
                    'animal_ids' => $novosAnimalIds,
                    'valor_bruto' => $novoValorBruto,
                    'cpv' => $novoCpv,
                    'deducao_fiscal' => $deducaoFiscal,
                    'fiscal_e_premissa' => $ehPremissa,
                    'receita_liquida' => $novaReceitaLiquida,
                ]);

                $this->registrarEvento('venda_corrigida', $fazendaId, $chaveIdempotencia, [
                    'tipo' => 'venda_corrigida', 'venda_original_id' => $vendaOriginalId,
                    'correcao_id' => $correcao->id, 'fazenda_id' => $fazendaId,
                ]);

                return [
                    'reenvio_detectado' => false,
                    'correcao' => $correcao,
                    'venda_original_id' => $vendaOriginalId,
                    'ids_que_saem' => $idsQueSaem,
                    'novo_cpv' => $novoCpv,
                    'nova_deducao_fiscal' => $deducaoFiscal,
                    'nova_receita_liquida' => $novaReceitaLiquida,
                ];
            });
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'venda' => Venda::where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    private function garantirRelacaoComFazenda(int $usuarioId, int $fazendaId): void
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($fazendaId)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com a Fazenda {$fazendaId}.");
        }
    }

    /**
     * VERTICAL-VENDA.md §3b — Configuração Fiscal mínima. A taxa segue como
     * PREMISSA (fiscal_e_premissa=true) até o produtor declarar a regra real
     * (§10) — nunca apresentada como fato numa UI antes disso.
     */
    private function calcularDeducaoFiscal(int $fazendaId, float $valorBruto): array
    {
        $responsavel = ResponsavelFiscal::find($fazendaId);
        $taxa = $responsavel?->taxa_venda_animal;
        $ehPremissa = $responsavel ? (bool) $responsavel->taxa_e_premissa : true;
        $deducao = $taxa !== null ? round($valorBruto * (float) $taxa, 2) : 0.0;

        return [$deducao, $ehPremissa];
    }

    private function registrarEvento(string $tipo, int $fazendaId, string $chaveIdempotenciaDoFato, array $payload): EventoDominio
    {
        return EventoDominio::create([
            'tipo' => $tipo,
            'fazenda_id' => $fazendaId,
            'chave_idempotencia' => $chaveIdempotenciaDoFato.':evento',
            'payload' => $payload,
            'status_consequencia' => 'pendente',
        ]);
    }

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
