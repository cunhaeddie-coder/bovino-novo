<?php

namespace Tests\Feature\VerticalFolhaPagamento;

use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\FormaPagamento;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\FolhaPagamentoService;
use App\Services\FormaPagamentoService;
use App\Services\FuncionarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 10 (Folha de Pagamento) — nasce de
 * VERTICAL-FOLHA-PAGAMENTO.md e SCHEMA-CONTRATO-FOLHA-PAGAMENTO.md.
 * Reproduz LAB-SA-008/LAB-FA-007 (salário nunca virava despesa).
 */
class FolhaPagamentoDominioTest extends TestCase
{
    use RefreshDatabase;

    private FuncionarioService $funcionarios;

    private FolhaPagamentoService $folhas;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->funcionarios = app(FuncionarioService::class);
        $this->folhas = app(FolhaPagamentoService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
    }

    /** LAB-SA-008/LAB-FA-007 — salário de R$3.000 finalmente vira despesa real. */
    public function test_contratacao_e_geracao_de_folha_criam_obrigacao_financeira_real(): void
    {
        $registro = $this->funcionarios->contratar($this->jose, $this->fazenda, 'Eddie', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', 'funcionario-1');
        $this->assertFalse($registro['reenvio_detectado']);

        $resultado = $this->folhas->gerarMes($this->jose, $this->fazenda, '2026-01');

        $this->assertCount(1, $resultado['geradas']);
        $this->assertEmpty($resultado['puladas']);

        $folha = $resultado['geradas'][0];
        $this->assertEqualsWithDelta(3000.00, (float) $folha->valor, 0.01);

        $obrigacao = ObrigacaoFinanceira::where('folha_pagamento_id', $folha->id)->first();
        $this->assertNotNull($obrigacao, 'folha_gera_obrigacao_financeira_real_diferente_do_atual');
        $this->assertSame('a_pagar', $obrigacao->direcao);
        $this->assertSame('pendente', $obrigacao->status, 'salario_nasce_pendente_nao_ja_liquidado');

        $forma = FormaPagamento::where('obrigacao_financeira_id', $obrigacao->id)->first();
        $this->assertNotNull($forma);
        $this->assertSame('dinheiro', $forma->unidade);
        $this->assertNull($forma->pago_em);
        $this->assertSame('2026-01-31', $forma->vencimento->format('Y-m-d'));

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'folha_pagamento_gerada')->first();
        $this->assertNotNull($evento);
    }

    /** A folha pendente é paga reaproveitando FormaPagamentoService::liquidar() já existente, sem alteração. */
    public function test_folha_pendente_e_liquidavel_pelo_mecanismo_ja_existente(): void
    {
        $this->funcionarios->contratar($this->jose, $this->fazenda, 'Eddie', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', 'funcionario-2');
        $resultado = $this->folhas->gerarMes($this->jose, $this->fazenda, '2026-01');
        $folha = $resultado['geradas'][0];
        $obrigacao = ObrigacaoFinanceira::where('folha_pagamento_id', $folha->id)->first();
        $forma = FormaPagamento::where('obrigacao_financeira_id', $obrigacao->id)->first();

        $liquidacao = app(FormaPagamentoService::class)->liquidar($this->jose, $forma->id);

        $this->assertNotNull($liquidacao['forma_pagamento']->pago_em);
        $this->assertSame('pago', $obrigacao->fresh()->status);
    }

    public function test_gerar_mes_pula_funcionario_que_ja_tem_folha_naquele_mes(): void
    {
        $this->funcionarios->contratar($this->jose, $this->fazenda, 'Eddie', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', 'funcionario-3');

        $primeira = $this->folhas->gerarMes($this->jose, $this->fazenda, '2026-01');
        $segunda = $this->folhas->gerarMes($this->jose, $this->fazenda, '2026-01');

        $this->assertCount(1, $primeira['geradas']);
        $this->assertEmpty($segunda['geradas']);
        $this->assertCount(1, $segunda['puladas']);
    }

    public function test_funcionario_desligado_nao_entra_em_gerar_mes(): void
    {
        $registro = $this->funcionarios->contratar($this->jose, $this->fazenda, 'Eddie', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', 'funcionario-4');
        $this->funcionarios->desligar($this->jose, $registro['funcionario']->id, '2026-01-15 08:00:00');

        $resultado = $this->folhas->gerarMes($this->jose, $this->fazenda, '2026-02');

        $this->assertEmpty($resultado['geradas']);
    }

    public function test_mudanca_de_salario_so_afeta_folhas_futuras(): void
    {
        $registro = $this->funcionarios->contratar($this->jose, $this->fazenda, 'Eddie', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', 'funcionario-5');
        $resultadoJaneiro = $this->folhas->gerarMes($this->jose, $this->fazenda, '2026-01');

        $registro['funcionario']->update(['salario' => 3500.00]);
        $resultadoFevereiro = $this->folhas->gerarMes($this->jose, $this->fazenda, '2026-02');

        $this->assertEqualsWithDelta(3000.00, (float) $resultadoJaneiro['geradas'][0]->fresh()->valor, 0.01);
        $this->assertEqualsWithDelta(3500.00, (float) $resultadoFevereiro['geradas'][0]->valor, 0.01);
    }
}
