<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\FormaPagamento;
use App\Models\Lote;
use App\Models\ObrigacaoFinanceira;
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

    // GATE-DECISAO-DOMINIO-DATA-HORA.md (04/09/2026) — $dataVenda é a data e
    // hora REAIS do fato (declarada pelo chamador, nunca inferida de now()
    // sozinho), distinta de created_at (prova de sistema). Formato
    // 'Y-m-d H:i:s' — mesma disciplina de $dataCompra em CompraService.
    public function registrar(int $usuarioId, int $fazendaId, array $animalIds, float $valorBruto, string $dataVenda, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        // Gate de Decisão de Domínio do Vertical Compra (27/08/2026) —
        // correção simétrica: a mesma lacuna (chave vazia aceita
        // silenciosamente) existia aqui também, nunca testada.
        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        // chave_idempotencia é escopada por Fazenda (revisão adversarial,
        // 26/08/2026) — uma colisão de chave com OUTRA Fazenda nunca pode
        // devolver a Venda de outra Fazenda (isso seria vazamento via INV-029).
        if ($existente = Venda::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'venda' => $existente];
        }

        try {
            return DB::transaction(function () use ($fazendaId, $animalIds, $valorBruto, $dataVenda, $chaveIdempotencia) {
                $animais = Animal::where('fazenda_id', $fazendaId)
                    ->whereIn('id', $animalIds)
                    ->where('status', 'ativo')
                    ->lockForUpdate()
                    ->get();

                if ($animais->count() !== count($animalIds)) {
                    // Achado real, confirmado contra MySQL (Spike 007, Ataque
                    // A — bovino-lab): uma transação CONCORRENTE com a MESMA
                    // chave_idempotencia pode ter vendido esses animais
                    // primeiro. Isso não é pedido inválido, é reenvio que
                    // perdeu a corrida pelo lock — sem esta checagem, INV-028
                    // se rompe sob concorrência real (SQLite nunca provou
                    // isso, porque lockForUpdate() é um no-op nesse driver).
                    $concorrente = Venda::where('fazenda_id', $fazendaId)
                        ->where('chave_idempotencia', $chaveIdempotencia)
                        ->lockForUpdate()
                        ->first();
                    if ($concorrente) {
                        return ['reenvio_detectado' => true, 'venda' => $concorrente];
                    }

                    throw new DomainException(
                        'Um ou mais animais pedidos não pertencem a esta Fazenda ou já não estão ativos — nenhum efeito parcial aplicado.'
                    );
                }

                $animais->each(fn (Animal $a) => $a->update(['status' => 'vendido', 'data_saida' => now()->toDateString()]));

                $idsVendidos = $animais->pluck('id')->values()->all();

                // INV-001 — recálculo do(s) lote(s) de origem, proporcional ao que saiu.
                // VERTICAL-COMPRA.md §9 — animal sem Lote (comprado individualmente,
                // custo_aquisicao próprio) não passa pela derivação de agregado: seu
                // custo já É o CPV dele, direto, nunca dividido de um lote que não existe.
                $cpv = 0.0;

                $semLote = $animais->whereNull('lote_id');
                if ($semLote->isNotEmpty()) {
                    $cpv += round((float) $semLote->sum('custo_aquisicao'), 2);
                }

                foreach ($animais->whereNotNull('lote_id')->groupBy('lote_id') as $loteId => $doLote) {
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
                    'data_venda' => $dataVenda,
                    'valor_bruto' => $valorBruto,
                    'cpv' => $cpv,
                    'deducao_fiscal' => $deducaoFiscal,
                    'fiscal_e_premissa' => $ehPremissa,
                    'receita_liquida' => $receitaLiquida,
                ]);

                // SCHEMA-CONTRATO-FORMA-PAGAMENTO.md §3, Opção A — Venda
                // nunca gerou nenhuma Obrigação Financeira antes desta
                // frente (assimetria real com Compra, achada só agora).
                // direcao=a_receber (é a Fazenda quem vai receber); valor é
                // o BRUTO combinado com o comprador, nunca a receita_liquida
                // (que já é líquida de CPV/dedução fiscal — dedução interna,
                // não parte do que o comprador efetivamente paga).
                $obrigacao = ObrigacaoFinanceira::create([
                    'fazenda_id' => $fazendaId,
                    'venda_id' => $venda->id,
                    'direcao' => 'a_receber',
                    'valor' => $valorBruto,
                ]);

                $this->criarFormaPagamentoAVista($obrigacao, $valorBruto, now()->toDateString());

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
                return ['reenvio_detectado' => true, 'venda' => Venda::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    /**
     * Correção do fato Venda (VERTICAL-VENDA.md §3c / INV-026). NUNCA faz
     * UPDATE na venda original — cria um novo registro que a referencia.
     *
     * Revisão adversarial (26/08/2026): $novosAnimalIds só pode REMOVER
     * animais do conjunto original, nunca introduzir um id que a venda
     * original nunca teve — VERTICAL-VENDA.md §3c/Caso B só descreve correção
     * como redução (28→26), nunca expansão, e um id não pertencente ao
     * original nunca passou por nenhuma checagem de disponibilidade/Fazenda.
     * Aceitar isso sem validar permitia "anexar" um animal de qualquer
     * Fazenda a uma correção sem nunca tocar o registro real desse animal.
     */
    public function corrigir(int $usuarioId, int $vendaOriginalId, array $novosAnimalIds, float $novoValorBruto, string $chaveIdempotencia): array
    {
        // Gate de Decisão de Domínio do Vertical Compra (27/08/2026) —
        // mesma correção simétrica de registrar().
        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        $original = $this->buscar($usuarioId, $vendaOriginalId);
        if (! $original) {
            throw new DomainException("Venda original #{$vendaOriginalId} não encontrada ou sem relação com a Fazenda do usuário {$usuarioId}.");
        }
        $fazendaId = $original->fazenda_id;
        $idsOriginais = $original->animal_ids;

        if (array_diff($novosAnimalIds, $idsOriginais)) {
            throw new DomainException(
                'Correção só pode remover animais da venda original, nunca incluir um animal que não fazia parte dela.'
            );
        }

        if ($existente = Venda::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'venda' => $existente];
        }

        $idsQueSaem = array_values(array_diff($idsOriginais, $novosAnimalIds));

        try {
            return DB::transaction(function () use ($original, $fazendaId, $idsOriginais, $idsQueSaem, $novosAnimalIds, $novoValorBruto, $chaveIdempotencia, $vendaOriginalId) {
                $custoUnitarioOriginal = $original->cpv / count($idsOriginais);

                if ($idsQueSaem) {
                    // bovino-lab/spikes/007-concorrencia-real-mysql/run_correcao.php,
                    // Ataque F — confirmado por execução real: sem o filtro
                    // por status, duas correções concorrentes devolvendo o
                    // MESMO animal creditavam o lote duas vezes (INV-001
                    // violado — o lote passava a "ter" um animal a mais do
                    // que fisicamente existe). Espelha o filtro que
                    // registrar() já usa pro caminho inverso (vender).
                    $animaisQueVoltam = Animal::where('fazenda_id', $fazendaId)
                        ->whereIn('id', $idsQueSaem)
                        ->where('status', 'vendido')
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

                // GATE-DECISAO-DOMINIO-DATA-HORA.md — a correção é seu
                // próprio fato novo (INV-026), sua data_venda é o momento
                // real em que a correção acontece, nunca herdada da venda
                // original (que já tem a sua própria).
                $correcao = Venda::create([
                    'fazenda_id' => $fazendaId,
                    'venda_original_id' => $vendaOriginalId,
                    'chave_idempotencia' => $chaveIdempotencia,
                    'animal_ids' => $novosAnimalIds,
                    'data_venda' => now(),
                    'valor_bruto' => $novoValorBruto,
                    'cpv' => $novoCpv,
                    'deducao_fiscal' => $deducaoFiscal,
                    'fiscal_e_premissa' => $ehPremissa,
                    'receita_liquida' => $novaReceitaLiquida,
                ]);

                // Mesmo padrão de registrar() — a correção é um novo fato
                // (venda imutável, INV-026), então ganha sua própria
                // Obrigação Financeira refletindo o valor bruto corrigido,
                // nunca uma atualização da obrigação da venda original.
                $novaObrigacao = ObrigacaoFinanceira::create([
                    'fazenda_id' => $fazendaId,
                    'venda_id' => $correcao->id,
                    'direcao' => 'a_receber',
                    'valor' => $novoValorBruto,
                ]);

                $this->criarFormaPagamentoAVista($novaObrigacao, $novoValorBruto, now()->toDateString());

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
                return ['reenvio_detectado' => true, 'venda' => Venda::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
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

    // VERTICAL-FORMA-PAGAMENTO.md §3 — mesmo padrão de CompraService/CompraInsumoService.
    private function criarFormaPagamentoAVista(ObrigacaoFinanceira $obrigacao, float $valorTotal, string $data): FormaPagamento
    {
        return FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id,
            'nome' => 'à vista',
            'unidade' => 'dinheiro',
            'valor' => $valorTotal,
            'data' => $data,
            'vencimento' => $data,
            'pago_em' => $data,
        ]);
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
