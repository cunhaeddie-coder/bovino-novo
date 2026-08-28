<?php

namespace Tests\Feature\VerticalCompra;

use App\Models\Animal;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\CompraService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Exceptions\MathException;
use Tests\TestCase;

/**
 * Revisão de fronteira do domínio Compra (27/08/2026) — não testa o fluxo
 * principal de novo (já fechado). Procura lacunas reais que os testes
 * atuais não cobrem, sem inventar validação que o domínio nunca declarou.
 */
class CompraFronteirasTest extends TestCase
{
    use RefreshDatabase;

    private CompraService $compras;

    private int $fazenda;

    private int $jose;

    private int $marilia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compras = app(CompraService::class);

        $this->fazenda = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->marilia = Fornecedor::create(['nome' => 'Marília'])->id;
    }

    /**
     * Achado real, corrigido: fornecedor_id inexistente violava a FK dentro
     * da transação, e violacaoDeUnicidade() confundia isso com colisão de
     * chave_idempotencia (mesma SQLSTATE 23000) — o chamador recebia
     * ModelNotFoundException em vez de um erro claro. Confirmado falhando
     * antes da correção (execução real), corrigido com checagem explícita.
     */
    public function test_fornecedor_inexistente_recusa_com_erro_claro_nao_apaga_nada(): void
    {
        $this->assertThrows(
            fn () => $this->compras->registrar($this->jose, $this->fazenda, 999999, [1000.00], '2026-01-01', 'compra-fornecedor-invalido'),
            DomainException::class
        );

        $this->assertSame(0, Compra::count());
        $this->assertSame(0, Animal::count());
    }

    /**
     * Fronteira transacional — a mais importante da revisão. Restaurada
     * (28/08/2026): a versão original deste teste usava `null` como preço
     * pra disparar o guard de Animal::booted() (lote_id XOR
     * custo_aquisicao) DEPOIS de 2 Animais válidos já escritos — mas a
     * validação de preço<=0 (Gate de Decisão de Domínio, mesmo dia)
     * passou a interceptar `null` ANTES da transação (`null <= 0` é
     * `true` em PHP), e o teste só continuava verde porque
     * `DomainException` é subclasse de `LogicException` no PHP —
     * deixou de exercitar rollback real, achado registrado em
     * `VERTICAL-COMPRA.md §15`.
     *
     * `NAN` restaura o mecanismo sem inventar nada novo: `NAN <= 0` é
     * `false` em PHP (qualquer comparação com NAN é falsa), então
     * atravessa a validação de preço sem ser barrado — igual ao `null`
     * original atravessava antes de existir aquela validação. Dentro da
     * transação, `array_sum`/`round` com `NAN` não derruba
     * `Compra::create()` (o cast decimal:2 de `valor_total` tolera),
     * mas `Animal::create(['custo_aquisicao' => NAN])` do 3º item
     * genuinamente falha — `Illuminate\Support\Exceptions\MathException`
     * ("Unable to cast value to a decimal"), que estende `RuntimeException`,
     * sem nenhuma relação com `LogicException`/`DomainException`.
     * Confirmado por execução real, reproduzido 3x: falha depois que
     * Compra + 2 Animais + seus CompraItens já foram escritos na mesma
     * transação — volta a provar rollback genuíno, não recusa
     * pré-transação.
     */
    public function test_falha_no_terceiro_animal_reverte_a_transacao_inteira(): void
    {
        $this->assertThrows(
            fn () => $this->compras->registrar($this->jose, $this->fazenda, $this->marilia, [5000.00, 3000.00, NAN], '2026-01-01', 'compra-rollback'),
            MathException::class
        );

        $this->assertSame(0, Compra::count(), 'compra_nao_persistida');
        $this->assertSame(0, CompraItem::count(), 'itens_nao_persistidos');
        $this->assertSame(0, Animal::count(), 'nem_os_dois_animais_que_seriam_validos_ficam');
        $this->assertSame(0, ObrigacaoFinanceira::count(), 'obrigacao_nao_persistida');
        $this->assertSame(0, EventoDominio::count(), 'evento_nao_persistido');
    }

    /** A relação CompraItem → Animal preserva a correspondência exata de valor. */
    public function test_compra_item_preserva_correspondencia_exata_com_seu_animal(): void
    {
        $resultado = $this->compras->registrar($this->jose, $this->fazenda, $this->marilia, [4000.00, 9000.00, 2500.00], '2026-01-01', 'compra-integridade');

        foreach ($resultado['animais'] as $animal) {
            $item = CompraItem::where('compra_id', $resultado['compra']->id)->where('animal_id', $animal->id)->first();

            $this->assertNotNull($item, "compra_item_existe_para_animal_{$animal->id}");
            $this->assertEqualsWithDelta((float) $animal->custo_aquisicao, (float) $item->valor, 0.01, "valor_do_item_bate_com_custo_do_animal_{$animal->id}");
        }

        $this->assertSame(3, CompraItem::where('compra_id', $resultado['compra']->id)->count(), 'nenhum_item_orfao_nenhum_animal_sem_item');
    }
}
