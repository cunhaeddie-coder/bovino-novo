<?php

namespace Tests\Feature\VerticalCompraInsumo;

use App\Models\CompraInsumo;
use App\Models\CompraInsumoItem;
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
 * Revisão de fronteira do domínio Compra de Insumo — mesma disciplina já
 * aplicada em Compra de Animal (CompraFronteirasTest): fornecedor
 * inexistente, Insumo de outra Fazenda (achado novo, sem análogo em Compra
 * de Animal), rollback transacional, integridade CompraInsumoItem→Insumo.
 */
class CompraInsumoFronteirasTest extends TestCase
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

    public function test_fornecedor_inexistente_recusa_com_erro_claro_nao_escreve_nada(): void
    {
        $this->assertThrows(
            fn () => $this->compras->registrar(
                $this->jose, $this->fazenda, 999999,
                [['insumo_novo' => ['nome' => 'Sal'], 'quantidade' => 10, 'valor_unitario' => 17.99]],
                '2026-01-01', 'compra-fornecedor-invalido'
            ),
            DomainException::class
        );

        $this->assertSame(0, CompraInsumo::count());
        $this->assertSame(0, Insumo::count());
    }

    /**
     * Achado novo, sem análogo em Compra de Animal: um insumo_id de outra
     * Fazenda nunca pode ser referenciado — mesma classe de bug que o
     * fornecedor_id inexistente já revelou (SQLSTATE 23000 compartilhada
     * entre violação de FK e de chave_idempotencia), checado explicitamente
     * antes da transação, mesmo padrão.
     */
    public function test_insumo_de_outra_fazenda_recusa_nao_vaza_entre_fazendas(): void
    {
        $fazendaB = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $insumoDeOutraFazenda = Insumo::create(['fazenda_id' => $fazendaB, 'nome' => 'Sal Branco 25kg']);

        $this->assertThrows(
            fn () => $this->compras->registrar(
                $this->jose, $this->fazenda, $this->marilia,
                [['insumo_id' => $insumoDeOutraFazenda->id, 'quantidade' => 10, 'valor_unitario' => 17.99]],
                '2026-01-01', 'compra-insumo-de-outra-fazenda'
            ),
            DomainException::class
        );

        $this->assertSame(0, CompraInsumo::count());
        $this->assertEqualsWithDelta(0, (float) $insumoDeOutraFazenda->fresh()->quantidade, 0.01, 'insumo_de_outra_fazenda_intocado');
    }

    /**
     * Achado real (28/08/2026), mesma classe do bug de Fornecedor: a
     * tentativa original deste teste foi forçar rollback via um insumo_novo
     * com nome já existente na Fazenda, esperando que a violação de
     * UNIQUE(fazenda_id, nome) estourasse DENTRO da transação (depois do
     * primeiro item já escrito) e revertesse tudo. Execução real revelou
     * outra coisa: violacaoDeUnicidade() (SQLSTATE 23000 genérica)
     * confundia essa violação com colisão de chave_idempotencia, e como a
     * CompraInsumo nunca chegava a commitar, o chamador recebia
     * ModelNotFoundException — não um erro que diz o que aconteceu.
     * Corrigido com checagem explícita ANTES da transação (mesmo padrão do
     * Fornecedor) — o que também significa que nenhum item inválido chega
     * perto de escrever nada, nunca precisando de rollback de fato pra este
     * caso específico.
     */
    public function test_insumo_novo_com_nome_ja_existente_recusa_antes_de_escrever_qualquer_coisa(): void
    {
        Insumo::create(['fazenda_id' => $this->fazenda, 'nome' => 'Fosbovi Advance 25kg']);

        $this->assertThrows(
            fn () => $this->compras->registrar(
                $this->jose, $this->fazenda, $this->marilia,
                [
                    ['insumo_novo' => ['nome' => 'Sal Branco 25kg'], 'quantidade' => 80, 'valor_unitario' => 17.99],
                    ['insumo_novo' => ['nome' => 'Fosbovi Advance 25kg'], 'quantidade' => 10, 'valor_unitario' => 220.00],
                ],
                '2026-01-01', 'compra-nome-existente'
            ),
            DomainException::class
        );

        $this->assertSame(0, CompraInsumo::count(), 'compra_nao_persistida');
        $this->assertSame(0, CompraInsumoItem::count(), 'itens_nao_persistidos');
        $this->assertSame(1, Insumo::count(), 'nem_o_sal_que_seria_valido_fica_so_o_fosbovi_preexistente_permanece');
        $this->assertSame(0, ObrigacaoFinanceira::count(), 'obrigacao_nao_persistida');
        $this->assertSame(0, EventoDominio::count(), 'evento_nao_persistido');
    }

    /** Mesma classe de achado, outra ponta: dois itens da mesma Compra referenciando o mesmo insumo_id existente. */
    public function test_mesmo_insumo_id_referenciado_duas_vezes_na_mesma_compra_recusa(): void
    {
        $insumo = Insumo::create(['fazenda_id' => $this->fazenda, 'nome' => 'Sal Branco 25kg']);

        $this->assertThrows(
            fn () => $this->compras->registrar(
                $this->jose, $this->fazenda, $this->marilia,
                [
                    ['insumo_id' => $insumo->id, 'quantidade' => 80, 'valor_unitario' => 17.99],
                    ['insumo_id' => $insumo->id, 'quantidade' => 10, 'valor_unitario' => 18.00],
                ],
                '2026-01-01', 'compra-insumo-duplicado'
            ),
            DomainException::class
        );

        $this->assertSame(0, CompraInsumo::count(), 'compra_nao_persistida');
        $this->assertEqualsWithDelta(0, (float) $insumo->fresh()->quantidade, 0.01, 'insumo_preexistente_intocado');
    }

    /** A relação CompraInsumoItem → Insumo preserva a correspondência exata de quantidade/valor. */
    public function test_compra_insumo_item_preserva_correspondencia_exata_com_seu_insumo(): void
    {
        $resultado = $this->compras->registrar(
            $this->jose, $this->fazenda, $this->marilia,
            [
                ['insumo_novo' => ['nome' => 'Sal Branco 25kg'], 'quantidade' => 80, 'valor_unitario' => 17.99],
                ['insumo_novo' => ['nome' => 'Fosbovi Advance 25kg'], 'quantidade' => 10, 'valor_unitario' => 220.00],
            ],
            '2026-01-01', 'compra-integridade'
        );

        foreach ($resultado['insumos'] as $insumo) {
            $item = CompraInsumoItem::where('compra_insumo_id', $resultado['compra']->id)->where('insumo_id', $insumo->id)->first();

            $this->assertNotNull($item, "item_existe_para_insumo_{$insumo->id}");
            $this->assertEqualsWithDelta((float) $insumo->quantidade, (float) $item->quantidade, 0.01, "quantidade_do_item_bate_com_insumo_{$insumo->id}");
            $this->assertEqualsWithDelta((float) $insumo->valor_referencia, (float) $item->valor_unitario, 0.01, "valor_do_item_bate_com_insumo_{$insumo->id}");
        }

        $this->assertSame(2, CompraInsumoItem::where('compra_insumo_id', $resultado['compra']->id)->count(), 'nenhum_item_orfao');
    }
}
