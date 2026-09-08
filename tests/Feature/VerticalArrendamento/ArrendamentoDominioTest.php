<?php

namespace Tests\Feature\VerticalArrendamento;

use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\FormaPagamento;
use App\Models\Fornecedor;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ArrendamentoService;
use App\Services\FormaPagamentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 12 (Arrendamento) — nasce de
 * VERTICAL-ARRENDAMENTO.md e SCHEMA-CONTRATO-ARRENDAMENTO.md. Reproduz
 * LAB-FA-020 (pasto de terceiro, R$45/cabeça/mês, 50 cabeças, 2 anos,
 * pagamento anual — R$54.000, deveria gerar 2 parcelas de R$27.000, não
 * parcelas mensais de 1/24 do total).
 */
class ArrendamentoDominioTest extends TestCase
{
    use RefreshDatabase;

    private ArrendamentoService $arrendamentos;

    private int $fazenda;

    private int $jose;

    private int $fornecedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrendamentos = app(ArrendamentoService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->fornecedor = Fornecedor::create(['nome' => 'Zé da Terra'])->id;
    }

    /** LAB-FA-020 — periodicidade anual finalmente gera parcelas anuais, não mensais. */
    public function test_periodicidade_anual_gera_2_parcelas_de_27000_nao_parcelas_mensais(): void
    {
        $registro = $this->arrendamentos->registrar(
            $this->jose, $this->fazenda, $this->fornecedor, 54000.00, 'anual',
            '2026-01-01 08:00:00', '2028-01-01 08:00:00', 'arrendamento-1'
        );
        $this->assertFalse($registro['reenvio_detectado']);

        $primeira = $this->arrendamentos->gerarProximaParcela($this->jose, $registro['arrendamento']->id);
        $segunda = $this->arrendamentos->gerarProximaParcela($this->jose, $registro['arrendamento']->id);

        $this->assertSame(1, $primeira['parcela']->numero_parcela);
        $this->assertSame(2, $segunda['parcela']->numero_parcela);
        $this->assertEqualsWithDelta(27000.00, (float) $primeira['parcela']->valor, 0.01);
        $this->assertEqualsWithDelta(27000.00, (float) $segunda['parcela']->valor, 0.01);

        // A 3ª parcela não existe — exatamente 2, nunca 24 (o bug do Atual).
        $this->assertThrows(
            fn () => $this->arrendamentos->gerarProximaParcela($this->jose, $registro['arrendamento']->id),
            \DomainException::class
        );

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'parcela_arrendamento_gerada')->count();
        $this->assertSame(2, $evento);
    }

    public function test_cada_parcela_gera_obrigacao_financeira_pendente_paga_pelo_mecanismo_ja_existente(): void
    {
        $registro = $this->arrendamentos->registrar(
            $this->jose, $this->fazenda, $this->fornecedor, 54000.00, 'anual',
            '2026-01-01 08:00:00', '2028-01-01 08:00:00', 'arrendamento-2'
        );
        $resultado = $this->arrendamentos->gerarProximaParcela($this->jose, $registro['arrendamento']->id);

        $obrigacao = ObrigacaoFinanceira::where('parcela_arrendamento_id', $resultado['parcela']->id)->first();
        $this->assertNotNull($obrigacao, 'parcela_gera_obrigacao_financeira_real_diferente_do_atual');
        $this->assertSame('a_pagar', $obrigacao->direcao);
        $this->assertSame('pendente', $obrigacao->status);

        $forma = FormaPagamento::where('obrigacao_financeira_id', $obrigacao->id)->first();
        $this->assertNotNull($forma);
        $this->assertNull($forma->pago_em);

        $liquidacao = app(FormaPagamentoService::class)->liquidar($this->jose, $forma->id);
        $this->assertNotNull($liquidacao['forma_pagamento']->pago_em);
        $this->assertSame('pago', $obrigacao->fresh()->status);
    }

    /** Divisão respeita valor_total mesmo quando não divide igual — última parcela absorve o resto. */
    public function test_ultima_parcela_absorve_resto_do_arredondamento(): void
    {
        $registro = $this->arrendamentos->registrar(
            $this->jose, $this->fazenda, $this->fornecedor, 1000.00, 'mensal',
            '2026-01-01 08:00:00', '2026-04-01 08:00:00', 'arrendamento-3'
        );

        $p1 = $this->arrendamentos->gerarProximaParcela($this->jose, $registro['arrendamento']->id);
        $p2 = $this->arrendamentos->gerarProximaParcela($this->jose, $registro['arrendamento']->id);
        $p3 = $this->arrendamentos->gerarProximaParcela($this->jose, $registro['arrendamento']->id);

        $soma = (float) $p1['parcela']->valor + (float) $p2['parcela']->valor + (float) $p3['parcela']->valor;
        $this->assertEqualsWithDelta(1000.00, $soma, 0.01);
    }

    public function test_reenvio_do_registro_e_idempotente(): void
    {
        $primeiro = $this->arrendamentos->registrar($this->jose, $this->fazenda, $this->fornecedor, 54000.00, 'anual', '2026-01-01 08:00:00', '2028-01-01 08:00:00', 'arrendamento-mesma-chave');
        $segundo = $this->arrendamentos->registrar($this->jose, $this->fazenda, $this->fornecedor, 54000.00, 'anual', '2026-01-01 08:00:00', '2028-01-01 08:00:00', 'arrendamento-mesma-chave');

        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['arrendamento']->id, $segundo['arrendamento']->id);
    }
}
