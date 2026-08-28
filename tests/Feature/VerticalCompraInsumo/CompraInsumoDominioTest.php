<?php

namespace Tests\Feature\VerticalCompraInsumo;

use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Insumo;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\CompraInsumoService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical Compra de Insumo — nasce de
 * VERTICAL-COMPRA-INSUMO.md e SCHEMA-CONTRATO-COMPRA-INSUMO.md, não do
 * código. Caso A reproduz LAB-SA-003 (80 sacos de Sal Branco + 10 de
 * Fosbovi, entrega única, um fornecedor, uma dívida) contra a implementação
 * real — o achado central que este vertical existe pra resolver: o Atual
 * nunca consegue as duas coisas juntas (estrutura certa + dívida certa).
 */
class CompraInsumoDominioTest extends TestCase
{
    use RefreshDatabase;

    private CompraInsumoService $compras;

    private int $fazenda;

    private int $jose;

    private int $marilia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compras = app(CompraInsumoService::class);

        $this->fazenda = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->marilia = Fornecedor::create(['nome' => 'Marília'])->id;
    }

    /** Caso A — LAB-SA-003: José recebe 80 sacos de Sal Branco + 10 de Fosbovi, entrega única, uma dívida só. */
    public function test_caso_a_compra_de_insumo_com_multiplos_itens_novos(): void
    {
        $resultado = $this->compras->registrar(
            $this->jose,
            $this->fazenda,
            $this->marilia,
            [
                ['insumo_novo' => ['nome' => 'Sal Branco 25kg'], 'quantidade' => 80, 'valor_unitario' => 17.99],
                ['insumo_novo' => ['nome' => 'Fosbovi Advance 25kg'], 'quantidade' => 10, 'valor_unitario' => 220.00],
            ],
            '2026-01-20',
            'compra-jose-insumo-2026-01-20'
        );

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertCount(2, $resultado['insumos']);

        $sal = Insumo::where('fazenda_id', $this->fazenda)->where('nome', 'Sal Branco 25kg')->first();
        $this->assertNotNull($sal, 'a1_insumo_sal_criado');
        $this->assertEqualsWithDelta(80, (float) $sal->quantidade, 0.01, 'a2_quantidade_sal_correta');
        $this->assertEqualsWithDelta(17.99, (float) $sal->valor_referencia, 0.01, 'a3_valor_referencia_sal_correto');

        $fosbovi = Insumo::where('fazenda_id', $this->fazenda)->where('nome', 'Fosbovi Advance 25kg')->first();
        $this->assertNotNull($fosbovi, 'a4_insumo_fosbovi_criado');
        $this->assertEqualsWithDelta(10, (float) $fosbovi->quantidade, 0.01, 'a5_quantidade_fosbovi_correta');
        $this->assertEqualsWithDelta(220.00, (float) $fosbovi->valor_referencia, 0.01, 'a6_valor_referencia_fosbovi_correto');

        $compra = $resultado['compra']->fresh();
        $valorEsperado = 80 * 17.99 + 10 * 220.00;
        $this->assertEqualsWithDelta($valorEsperado, (float) $compra->valor_total, 0.01, 'a7_valor_total_e_soma_dos_itens');
        $this->assertSame($this->marilia, $compra->fornecedor_id, 'a8_fornecedor_correto');
        $this->assertSame('2026-01-20', $compra->data_compra->toDateString(), 'a9_data_real_nao_data_de_digitacao');

        // O achado central de LAB-SA-003: UMA obrigação só, somando os dois itens — nunca zero, nunca fragmentada.
        $obrigacao = ObrigacaoFinanceira::where('compra_insumo_id', $compra->id)->first();
        $this->assertNotNull($obrigacao, 'a10_obrigacao_financeira_existe_nao_fica_muda_como_no_atual');
        $this->assertEqualsWithDelta($valorEsperado, (float) $obrigacao->valor, 0.01, 'a11_obrigacao_valor_correto');
        $this->assertSame('pago', $obrigacao->status, 'a12_pago_imediato_a_vista');
        $this->assertSame(1, ObrigacaoFinanceira::where('compra_insumo_id', $compra->id)->count(), 'a13_exatamente_uma_obrigacao_nunca_fragmentada_por_item');

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'compra_insumo_concluida')->first();
        $this->assertNotNull($evento, 'a14_evento_dominio_existe');

        // Reenvio — mesma chave, nunca reprocessa, nunca duplica insumo.
        $reenvio = $this->compras->registrar(
            $this->jose,
            $this->fazenda,
            $this->marilia,
            [['insumo_novo' => ['nome' => 'Sal Branco 25kg'], 'quantidade' => 80, 'valor_unitario' => 17.99]],
            '2026-01-20',
            'compra-jose-insumo-2026-01-20'
        );
        $this->assertTrue($reenvio['reenvio_detectado'], 'a15_reenvio_detectado');
        $this->assertSame($compra->id, $reenvio['compra']->id, 'a15_mesma_compra_retornada');
        $this->assertSame(2, Insumo::where('fazenda_id', $this->fazenda)->count(), 'a16_nenhum_insumo_duplicado_por_reenvio');
    }

    /** Segunda Compra do mesmo Insumo — reposição: soma quantidade, atualiza valor_referencia pro preço mais recente. */
    public function test_reposicao_de_insumo_existente_soma_quantidade_atualiza_valor_referencia(): void
    {
        $primeira = $this->compras->registrar(
            $this->jose,
            $this->fazenda,
            $this->marilia,
            [['insumo_novo' => ['nome' => 'Sal Branco 25kg'], 'quantidade' => 80, 'valor_unitario' => 17.99]],
            '2026-01-20',
            'compra-1'
        );
        $insumoId = $primeira['insumos'][0]->id;

        $segunda = $this->compras->registrar(
            $this->jose,
            $this->fazenda,
            $this->marilia,
            [['insumo_id' => $insumoId, 'quantidade' => 95, 'valor_unitario' => 18.00]],
            '2026-03-01',
            'compra-2'
        );

        $this->assertFalse($segunda['reenvio_detectado']);
        $this->assertSame(1, Insumo::where('fazenda_id', $this->fazenda)->count(), 'b1_nenhum_insumo_novo_criado_e_reposicao');

        $insumo = Insumo::find($insumoId)->fresh();
        $this->assertEqualsWithDelta(80 + 95, (float) $insumo->quantidade, 0.01, 'b2_quantidade_soma_nao_substitui');
        $this->assertEqualsWithDelta(18.00, (float) $insumo->valor_referencia, 0.01, 'b3_valor_referencia_e_o_mais_recente_nao_media');
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_registra_compra(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;

        $this->assertThrows(
            fn () => $this->compras->registrar(
                $mariazinha,
                $this->fazenda,
                $this->marilia,
                [['insumo_novo' => ['nome' => 'Sal'], 'quantidade' => 1, 'valor_unitario' => 10]],
                '2026-01-01',
                'ataque-mariazinha'
            ),
            DomainException::class
        );
    }
}
