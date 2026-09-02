<?php

namespace Tests\Feature\VerticalFormaPagamento;

use App\Models\Animal;
use App\Models\Compra;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\FormaPagamento;
use App\Models\FormaPagamentoHistorico;
use App\Models\Fornecedor;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Models\Venda;
use App\Services\CompraService;
use App\Services\FormaPagamentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste do FormaPagamentoService real, contra cenários do próprio
 * VERTICAL-FORMA-PAGAMENTO.md §1/§2/§5 (liquidação em dinheiro, arroba,
 * espécie nos dois sentidos) e §6 (edição preserva histórico).
 */
class FormaPagamentoDominioTest extends TestCase
{
    use RefreshDatabase;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fazenda = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
    }

    public function test_registrar_compra_ja_nasce_com_forma_de_pagamento_a_vista_liquidada(): void
    {
        $fornecedor = Fornecedor::create(['nome' => 'Marília'])->id;
        $resultado = app(CompraService::class)->registrar($this->jose, $this->fazenda, $fornecedor, [5000.00], '2026-01-10', 'compra-avista');

        $obrigacao = $resultado['obrigacao_financeira']->fresh();
        $forma = $obrigacao->formasPagamento->sole();

        $this->assertSame('à vista', $forma->nome);
        $this->assertSame('dinheiro', $forma->unidade);
        $this->assertNotNull($forma->pago_em);
        $this->assertSame('pago', $obrigacao->status);
    }

    public function test_liquidar_forma_de_pagamento_pendente_em_dinheiro(): void
    {
        // Simula uma Forma de Pagamento "a prazo" — cenário que registrar()
        // ainda não declara diretamente (parcelamento fica pra frente
        // seguinte), mas FormaPagamentoService::liquidar() é genérico.
        $obrigacao = $this->criarObrigacaoPendente('a_pagar');
        $forma = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'parcela 1/1', 'unidade' => 'dinheiro',
            'valor' => 500.00, 'data' => '2026-01-01', 'vencimento' => '2026-02-01',
        ]);
        $this->assertSame('pendente', $obrigacao->fresh()->status);

        $resultado = app(FormaPagamentoService::class)->liquidar($this->jose, $forma->id);

        $this->assertFalse($resultado['ja_liquidada']);
        $this->assertNotNull($resultado['forma_pagamento']->pago_em);
        $this->assertEqualsWithDelta(500.00, (float) $resultado['forma_pagamento']->valor_liquidado_reais, 0.01);
        $this->assertSame('pago', $obrigacao->fresh()->status);

        // VERTICAL-FORMA-PAGAMENTO.md §4 — outbox real, mesmo mecanismo genérico.
        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'forma_pagamento_liquidada')->first();
        $this->assertNotNull($evento, 'liquidar_dispara_evento_de_outbox');
        $this->assertSame($forma->id, $evento->payload['forma_pagamento_id']);
    }

    public function test_liquidar_em_arroba_converte_pela_cotacao_da_liquidacao_nunca_antes(): void
    {
        $obrigacao = $this->criarObrigacaoPendente('a_pagar');
        $forma = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id, 'nome' => '100 arrobas', 'unidade' => 'arroba',
            'valor' => 100, 'data' => '2026-01-01', 'vencimento' => '2027-01-01',
        ]);

        $this->assertNull($forma->fresh()->valor_liquidado_reais, 'nao_tem_valor_em_reais_antes_de_liquidar');

        $resultado = app(FormaPagamentoService::class)->liquidar($this->jose, $forma->id, cotacaoArroba: 320.00);

        $this->assertEqualsWithDelta(32000.00, (float) $resultado['forma_pagamento']->valor_liquidado_reais, 0.01);
        $this->assertEqualsWithDelta(320.00, (float) $resultado['forma_pagamento']->cotacao_arroba_na_liquidacao, 0.01);
    }

    public function test_liquidacao_em_especie_a_pagar_dispara_baixa_real_do_animal(): void
    {
        $fornecedor = Fornecedor::create(['nome' => 'Marília'])->id;
        $resultadoCompra = app(CompraService::class)->registrar($this->jose, $this->fazenda, $fornecedor, [9000.00], '2026-01-10', 'compra-p-espécie');
        $animalEntregue = $resultadoCompra['animais'][0];

        $obrigacao = $this->criarObrigacaoPendente('a_pagar');
        $forma = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'entrada com animal', 'unidade' => 'dinheiro',
            'valor' => 9000.00, 'data' => '2026-01-10', 'vencimento' => '2026-01-10', 'meio_liquidacao' => 'especie',
        ]);

        $resultado = app(FormaPagamentoService::class)->liquidar($this->jose, $forma->id, animalId: $animalEntregue->id);

        $this->assertSame($animalEntregue->id, $resultado['forma_pagamento']->animal_id);
        $this->assertSame('vendido', $animalEntregue->fresh()->status, 'baixa_real_nao_so_um_numero_no_valor');
        // liquidar() grava a data REAL da liquidação (agora), não a data
        // combinada na origem da Forma de Pagamento — mesmo espírito de
        // "data de pagamento real, separada do vencimento" (§2/§9 pergunta 3).
        $this->assertSame(now()->toDateString(), $animalEntregue->fresh()->data_saida->toDateString());
    }

    public function test_liquidacao_em_especie_a_receber_dispara_entrada_real_do_animal(): void
    {
        $obrigacao = $this->criarObrigacaoPendente('a_receber');
        $forma = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'recebido em animal', 'unidade' => 'dinheiro',
            'valor' => 7000.00, 'data' => '2026-01-10', 'vencimento' => '2026-01-10', 'meio_liquidacao' => 'especie',
        ]);

        $antes = Animal::where('fazenda_id', $this->fazenda)->count();
        $resultado = app(FormaPagamentoService::class)->liquidar($this->jose, $forma->id, animalId: 0);

        $this->assertSame($antes + 1, Animal::where('fazenda_id', $this->fazenda)->count(), 'entrada_real_novo_animal_no_rebanho');
        $animalRecebido = Animal::find($resultado['forma_pagamento']->animal_id);
        $this->assertSame('ativo', $animalRecebido->status);
        $this->assertEqualsWithDelta(7000.00, (float) $animalRecebido->custo_aquisicao, 0.01);
        $this->assertNull($animalRecebido->lote_id);
    }

    public function test_editar_forma_de_pagamento_preserva_historico_do_estado_anterior(): void
    {
        $obrigacao = $this->criarObrigacaoPendente('a_pagar');
        $forma = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'parcela 1', 'unidade' => 'dinheiro',
            'valor' => 500.00, 'data' => '2026-01-01', 'vencimento' => '2026-02-01',
        ]);

        $editada = app(FormaPagamentoService::class)->editar($this->jose, $forma->id, ['vencimento' => '2026-03-01']);

        $this->assertSame('2026-03-01', $editada->vencimento->toDateString());
        $historico = FormaPagamentoHistorico::where('forma_pagamento_id', $forma->id)->sole();
        $this->assertSame('2026-02-01', $historico->vencimento->toDateString(), 'historico_guarda_o_vencimento_ANTERIOR');
    }

    private function criarObrigacaoPendente(string $direcao): ObrigacaoFinanceira
    {
        if ($direcao === 'a_pagar') {
            $fornecedor = Fornecedor::create(['nome' => 'Marília'])->id;
            $compra = Compra::create([
                'fazenda_id' => $this->fazenda, 'fornecedor_id' => $fornecedor,
                'chave_idempotencia' => 'compra-'.uniqid(), 'data_compra' => '2026-01-01', 'valor_total' => 100000,
            ]);

            return ObrigacaoFinanceira::create([
                'fazenda_id' => $this->fazenda, 'compra_id' => $compra->id, 'direcao' => 'a_pagar', 'valor' => 100000,
            ]);
        }

        $venda = Venda::create([
            'fazenda_id' => $this->fazenda, 'chave_idempotencia' => 'venda-'.uniqid(),
            'animal_ids' => [], 'valor_bruto' => 100000, 'cpv' => 0, 'deducao_fiscal' => 0,
            'fiscal_e_premissa' => true, 'receita_liquida' => 100000,
        ]);

        return ObrigacaoFinanceira::create([
            'fazenda_id' => $this->fazenda, 'venda_id' => $venda->id, 'direcao' => 'a_receber', 'valor' => 100000,
        ]);
    }
}
