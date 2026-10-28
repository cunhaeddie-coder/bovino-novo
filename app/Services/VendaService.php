<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\FormaPagamento;
use App\Models\LancamentoFiscal;
use App\Models\Lote;
use App\Models\ObrigacaoFinanceira;
use App\Models\ResponsavelFiscal;
use App\Models\Usuario;
use App\Models\Venda;
use App\Services\Concerns\RegistraEventoDominio;
use Carbon\Carbon;
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
    use RegistraEventoDominio;

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

        // INV-002 — venda sem valor não tem consequência financeira real
        // (conferência das invariantes, 27/10/2026). GTA, Separação e
        // Marketplace já recusavam na entrada; a venda direta não.
        if ($valorBruto <= 0) {
            throw new DomainException("Venda exige valor maior que zero (recebido: {$valorBruto}).");
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

                // INV-008 — a saída, o pagamento à vista e o lançamento fiscal
                // ficam na data real da venda, nunca na da digitação (mesma
                // disciplina de MorteService e CompraService).
                $diaDaVenda = Carbon::parse($dataVenda)->toDateString();
                $animais->each(fn (Animal $a) => $a->update(['status' => 'vendido', 'data_saida' => $diaDaVenda]));

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

                [$deducaoFiscal, $ehPremissa, $taxaAplicada] = $this->calcularDeducaoFiscal($fazendaId, $valorBruto);
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

                // SCHEMA-CONTRATO-CONFIGURACAO-FISCAL.md §4 — achado real,
                // reabre o Vertical 1: o fato fiscal nunca ficava
                // persistido em lugar nenhum (LAB-SA-001/013/021), só
                // dentro do cálculo de receita_liquida. INV-061.
                LancamentoFiscal::create([
                    'venda_id' => $venda->id,
                    'deducao_fiscal' => $deducaoFiscal,
                    'taxa_aplicada' => $taxaAplicada,
                    'fiscal_e_premissa' => $ehPremissa,
                    'data_lancamento' => $dataVenda,
                ]);

                // SCHEMA-CONTRATO-INTELIGENCIA-MERCADO.md §5 — pivot venda_animal,
                // pré-requisito pra consultar Venda pelo lado do Animal (cotações
                // por raça/estado). Acompanha o que este método já decidiu, nunca
                // reabre nenhuma checagem nova.
                $venda->animais()->attach($idsVendidos);

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

                $this->criarFormaPagamentoAVista($obrigacao, $valorBruto, $diaDaVenda);

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
     * UPDATE na versão corrigida — cria um novo registro que a referencia.
     *
     * Decisões do produtor (28/10/2026, VERTICAL-VENDA.md §12): corrigir é
     * consertar um erro de registro (V1) — a venda passa a valer uma vez só,
     * pela versão atual, na mesma data real da venda, e a Carteira recebe só
     * a diferença (LancamentoService). Correções encadeiam (V2): só a versão
     * atual pode ser corrigida, e a correção simultânea da mesma versão é
     * recusada.
     *
     * Revisão adversarial (26/08/2026): $novosAnimalIds só pode REMOVER
     * animais da versão corrigida, nunca introduzir um id que ela nunca teve
     * — VERTICAL-VENDA.md §3c/Caso B só descreve correção como redução
     * (28→26), nunca expansão, e um id não pertencente à venda nunca passou
     * por nenhuma checagem de disponibilidade/Fazenda. Aceitar isso sem
     * validar permitia "anexar" um animal de qualquer Fazenda a uma correção
     * sem nunca tocar o registro real desse animal.
     */
    public function corrigir(int $usuarioId, int $vendaId, array $novosAnimalIds, float $novoValorBruto, string $chaveIdempotencia): array
    {
        // Gate de Decisão de Domínio do Vertical Compra (27/08/2026) —
        // mesma correção simétrica de registrar().
        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        $versao = $this->buscar($usuarioId, $vendaId);
        if (! $versao) {
            throw new DomainException("Venda #{$vendaId} não encontrada ou sem relação com a Fazenda do usuário {$usuarioId}.");
        }
        $fazendaId = $versao->fazenda_id;
        $idsDaVersao = $versao->animal_ids;

        // Reenvio vem antes da checagem de versão atual: a versão já foi
        // corrigida justamente por este mesmo pedido.
        if ($existente = Venda::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'venda' => $existente, 'correcao' => $existente];
        }

        if ($novoValorBruto <= 0) {
            throw new DomainException("Correção de venda exige valor maior que zero (recebido: {$novoValorBruto}).");
        }

        if (empty($novosAnimalIds)) {
            throw new DomainException('A correção mantém ao menos 1 animal na venda.');
        }

        if (array_diff($novosAnimalIds, $idsDaVersao)) {
            throw new DomainException(
                'Correção só pode remover animais da venda, nunca incluir um animal que não fazia parte dela.'
            );
        }

        $this->garantirVersaoAtual($versao);

        $idsQueSaem = array_values(array_diff($idsDaVersao, $novosAnimalIds));

        try {
            return DB::transaction(function () use ($versao, $fazendaId, $idsDaVersao, $idsQueSaem, $novosAnimalIds, $novoValorBruto, $chaveIdempotencia) {
                // V2 — duas correções simultâneas da mesma versão: a segunda
                // espera este lock, vê a primeira e é recusada. O UNIQUE em
                // vendas.venda_original_id é a última barreira.
                Venda::whereKey($versao->id)->lockForUpdate()->first();
                $this->garantirVersaoAtual($versao);

                $custoUnitarioOriginal = $versao->cpv / count($idsDaVersao);

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

                [$deducaoFiscal, $ehPremissa, $taxaAplicada] = $this->calcularDeducaoFiscal($fazendaId, $novoValorBruto);
                $novoCpv = round($custoUnitarioOriginal * count($novosAnimalIds), 2);
                $novaReceitaLiquida = round($novoValorBruto - $novoCpv - $deducaoFiscal, 2);

                // V1 (28/10/2026) — a correção conserta o registro de uma
                // venda que aconteceu numa data real: a versão nova mantém
                // essa data (INV-008). O momento da correção fica em
                // created_at. Substitui a regra de 04/09 (GATE-DECISAO-
                // DOMINIO-DATA-HORA.md), que datava a correção pelo clique.
                $correcao = Venda::create([
                    'fazenda_id' => $fazendaId,
                    'venda_original_id' => $versao->id,
                    'chave_idempotencia' => $chaveIdempotencia,
                    'animal_ids' => $novosAnimalIds,
                    'data_venda' => $versao->data_venda,
                    'valor_bruto' => $novoValorBruto,
                    'cpv' => $novoCpv,
                    'deducao_fiscal' => $deducaoFiscal,
                    'fiscal_e_premissa' => $ehPremissa,
                    'receita_liquida' => $novaReceitaLiquida,
                ]);

                // SCHEMA-CONTRATO-CONFIGURACAO-FISCAL.md §4 — a correção é
                // seu próprio fato (INV-026), ganha seu próprio
                // LancamentoFiscal, igual a registrar(). INV-061.
                LancamentoFiscal::create([
                    'venda_id' => $correcao->id,
                    'deducao_fiscal' => $deducaoFiscal,
                    'taxa_aplicada' => $taxaAplicada,
                    'fiscal_e_premissa' => $ehPremissa,
                    'data_lancamento' => $versao->data_venda,
                ]);

                // SCHEMA-CONTRATO-INTELIGENCIA-MERCADO.md §5 — a correção é sua
                // própria linha (INV-026), então ganha sua própria pivot
                // venda_animal, igual a registrar(). Sem isso, a query de
                // cotações (que passa a ignorar o original já corrigido) ficaria
                // sem nenhuma linha pra esta venda.
                $correcao->animais()->attach($novosAnimalIds);

                // Mesmo padrão de registrar() — a correção é um novo fato
                // (venda imutável, INV-026), então ganha sua própria
                // Obrigação Financeira refletindo o valor bruto corrigido,
                // nunca uma atualização da obrigação da versão anterior. A
                // venda vale pela versão atual (Venda::atuais()); na Carteira,
                // o lançamento desta forma é só a diferença (V1).
                $novaObrigacao = ObrigacaoFinanceira::create([
                    'fazenda_id' => $fazendaId,
                    'venda_id' => $correcao->id,
                    'direcao' => 'a_receber',
                    'valor' => $novoValorBruto,
                ]);

                $this->criarFormaPagamentoAVista($novaObrigacao, $novoValorBruto, $versao->data_venda->toDateString());

                $this->registrarEvento('venda_corrigida', $fazendaId, $chaveIdempotencia, [
                    'tipo' => 'venda_corrigida', 'venda_original_id' => $versao->id,
                    'correcao_id' => $correcao->id, 'fazenda_id' => $fazendaId,
                ]);

                return [
                    'reenvio_detectado' => false,
                    'venda' => $correcao,
                    'correcao' => $correcao,
                    'venda_original_id' => $versao->id,
                    'ids_que_saem' => $idsQueSaem,
                    'novo_cpv' => $novoCpv,
                    'nova_deducao_fiscal' => $deducaoFiscal,
                    'nova_receita_liquida' => $novaReceitaLiquida,
                ];
            });
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                $existente = Venda::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first();
                if ($existente) {
                    return ['reenvio_detectado' => true, 'venda' => $existente, 'correcao' => $existente];
                }
                // UNIQUE em venda_original_id: outra correção da mesma versão
                // entrou primeiro (V2).
                $this->garantirVersaoAtual($versao);
            }
            throw $e;
        }
    }

    // V2 (28/10/2026) — só a versão atual de uma venda pode ser corrigida;
    // corrigir de novo é sempre sobre a correção mais recente.
    private function garantirVersaoAtual(Venda $versao): void
    {
        $substituta = Venda::where('venda_original_id', $versao->id)->first();
        if ($substituta) {
            throw new DomainException("Esta venda acabou de ser corrigida (pela venda #{$substituta->id}). Abra a venda de novo para corrigir a versão atual.");
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
     *
     * SCHEMA-CONTRATO-CONFIGURACAO-FISCAL.md §4 — passa a devolver a taxa
     * aplicada (3º valor), já calculada internamente, pra LancamentoFiscal
     * poder congelá-la (INV-062). INV-063: sem ResponsavelFiscal, dedução
     * zero explícita, nunca um erro.
     *
     * @return array{0: float, 1: bool, 2: ?float}
     */
    private function calcularDeducaoFiscal(int $fazendaId, float $valorBruto): array
    {
        $responsavel = ResponsavelFiscal::find($fazendaId);
        $taxa = $responsavel?->taxa_venda_animal;
        $ehPremissa = $responsavel ? (bool) $responsavel->taxa_e_premissa : true;
        $deducao = $taxa !== null ? round($valorBruto * (float) $taxa, 2) : 0.0;

        return [$deducao, $ehPremissa, $taxa !== null ? (float) $taxa : null];
    }

    // VERTICAL-FORMA-PAGAMENTO.md §3 — mesmo padrão de CompraService/CompraInsumoService.
    //
    // SCHEMA-CONTRATO-CARTEIRA.md §4 — achado real ao implementar o
    // Vertical 27: à vista nasce JÁ paga, sem nunca passar por
    // FormaPagamentoService::liquidar() — antes desta correção, o evento
    // forma_pagamento_liquidada nunca era emitido pra este caminho (o mais
    // comum do projeto), deixando a Carteira cega pra toda Venda à vista.
    // valor_liquidado_reais também passa a ser gravado aqui, mesmo
    // cálculo que liquidar() faz pra unidade=dinheiro (valor = valor
    // liquidado, sem conversão de arroba).
    private function criarFormaPagamentoAVista(ObrigacaoFinanceira $obrigacao, float $valorTotal, string $data): FormaPagamento
    {
        $forma = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id,
            'nome' => 'à vista',
            'unidade' => 'dinheiro',
            'valor' => $valorTotal,
            'data' => $data,
            'vencimento' => $data,
            'pago_em' => $data,
            'valor_liquidado_reais' => $valorTotal,
        ]);

        $this->registrarEvento('forma_pagamento_liquidada', $obrigacao->fazenda_id, "forma-pagamento-{$forma->id}", [
            'tipo' => 'forma_pagamento_liquidada', 'forma_pagamento_id' => $forma->id,
            'obrigacao_financeira_id' => $obrigacao->id, 'fazenda_id' => $obrigacao->fazenda_id,
        ]);

        return $forma;
    }

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
