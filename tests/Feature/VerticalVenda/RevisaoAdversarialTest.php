<?php

namespace Tests\Feature\VerticalVenda;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Lote;
use App\Models\Papel;
use App\Models\Usuario;
use App\Models\Venda;
use App\Services\VendaService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Revisão adversarial do Vertical Venda (26/08/2026) — não procura confirmar
 * que o código funciona, procura o que o Spike 006 não testou. Cada teste
 * aqui reproduz um achado CONFIRMADO por execução real durante a revisão,
 * antes da correção existir, e trava o comportamento correto como regressão
 * permanente. Ver BOVINO-NOVO.md, entrada "Revisão adversarial do Vertical
 * Venda".
 */
class RevisaoAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private VendaService $vendas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendas = app(VendaService::class);
    }

    /**
     * Achado 1 (crítico) — chave_idempotencia era UNIQUE global. Duas
     * Fazendas sem nenhuma relação, usando por coincidência a mesma chave
     * (ex: contador ingênuo do cliente), faziam o dedup de uma devolver a
     * Venda da outra — vazamento de dado financeiro entre Fazendas via
     * INV-029. Confirmado por execução real antes da correção; agora
     * chave_idempotencia é UNIQUE por (fazenda_id, chave_idempotencia).
     */
    public function test_colisao_de_chave_idempotencia_entre_fazendas_nunca_vaza_venda_de_outra(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);

        $loteA = Lote::create(['fazenda_id' => $fazendaA, 'qtd_animais' => 2, 'custo_aquisicao' => 200])->id;
        $a1 = Animal::create(['fazenda_id' => $fazendaA, 'lote_id' => $loteA, 'status' => 'ativo'])->id;
        $a2 = Animal::create(['fazenda_id' => $fazendaA, 'lote_id' => $loteA, 'status' => 'ativo'])->id;

        $loteB = Lote::create(['fazenda_id' => $fazendaB, 'qtd_animais' => 2, 'custo_aquisicao' => 500])->id;
        $b1 = Animal::create(['fazenda_id' => $fazendaB, 'lote_id' => $loteB, 'status' => 'ativo'])->id;
        $b2 = Animal::create(['fazenda_id' => $fazendaB, 'lote_id' => $loteB, 'status' => 'ativo'])->id;

        $vendaJose = $this->vendas->registrar($jose, $fazendaA, [$a1, $a2], 999.99, 'venda-001');

        // Mariazinha, sem nenhuma relação com José, usa por coincidência a mesma chave.
        $resultado = $this->vendas->registrar($mariazinha, $fazendaB, [$b1, $b2], 5000.00, 'venda-001');

        $this->assertFalse($resultado['reenvio_detectado'], 'chaves coincidentes em Fazendas diferentes são operações distintas, não reenvio');
        $this->assertNotSame($vendaJose['venda']->id, $resultado['venda']->id, 'Mariazinha nunca pode receber a Venda de José de volta');
        $this->assertSame($fazendaB, $resultado['venda']->fazenda_id);
        $this->assertSame('vendido', Animal::find($b1)->status, 'a venda de Mariazinha precisa ter efeito real, não só retornar sem processar');
    }

    /**
     * Achado 2 (alto) — corrigir() nunca validava que $novosAnimalIds fosse
     * subconjunto do original. Um id nunca vendido (inclusive de outra
     * Fazenda inteira) podia ser "anexado" à correção sem passar por nenhuma
     * checagem de disponibilidade ou isolamento — o animal real nunca era
     * tocado, mas a Venda passava a "afirmar" que ele fazia parte da venda.
     */
    public function test_correcao_nao_pode_incluir_animal_que_nao_estava_na_venda_original(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);

        $loteA = Lote::create(['fazenda_id' => $fazendaA, 'qtd_animais' => 3, 'custo_aquisicao' => 300])->id;
        $a1 = Animal::create(['fazenda_id' => $fazendaA, 'lote_id' => $loteA, 'status' => 'ativo'])->id;
        $a2 = Animal::create(['fazenda_id' => $fazendaA, 'lote_id' => $loteA, 'status' => 'ativo'])->id;
        $a3 = Animal::create(['fazenda_id' => $fazendaA, 'lote_id' => $loteA, 'status' => 'ativo'])->id;

        $loteB = Lote::create(['fazenda_id' => $fazendaB, 'qtd_animais' => 1, 'custo_aquisicao' => 1000])->id;
        $animalDeOutraFazenda = Animal::create(['fazenda_id' => $fazendaB, 'lote_id' => $loteB, 'status' => 'ativo'])->id;

        $venda = $this->vendas->registrar($jose, $fazendaA, [$a1, $a2, $a3], 300.00, 'venda-1');

        $this->assertThrows(
            fn () => $this->vendas->corrigir($jose, $venda['venda']->id, [$a1, $a2, $animalDeOutraFazenda], 300.00, 'correcao-1'),
            DomainException::class
        );

        $this->assertSame('ativo', Animal::find($animalDeOutraFazenda)->status, 'animal de outra Fazenda não pode ser afetado por uma correção que nunca deveria alcançá-lo');
        $this->assertSame([$a1, $a2, $a3], $venda['venda']->fresh()->animal_ids, 'venda original não pode ter sido alterada pela tentativa');
    }

    /**
     * Achado 3 (médio-alto) — o guard de INV-026 em Venda::booted() só
     * intercepta $model->update()/->save() numa instância carregada; uma
     * atualização em massa via query builder (Venda::where(...)->update())
     * não dispara eventos de model e contornava a imutabilidade por completo.
     * Fechado com um Eloquent Builder próprio (App\Models\Builders\VendaBuilder)
     * que bloqueia update()/delete() nesse nível também.
     *
     * Risco residual, documentado e não escondido: DB::table('vendas')->update()
     * ainda contorna esta camada — fechar isso por completo exige um gatilho
     * no banco, fora do escopo mínimo deste vertical.
     */
    public function test_venda_e_imutavel_mesmo_via_query_builder_em_massa(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A'])->id;
        $venda = Venda::create([
            'fazenda_id' => $fazenda, 'venda_original_id' => null, 'chave_idempotencia' => 'k1',
            'animal_ids' => [1, 2, 3], 'valor_bruto' => 100, 'cpv' => 10, 'deducao_fiscal' => 0,
            'fiscal_e_premissa' => true, 'receita_liquida' => 90,
        ]);

        $this->assertThrows(fn () => $venda->update(['valor_bruto' => 999]), LogicException::class);
        $this->assertThrows(fn () => Venda::where('id', $venda->id)->update(['valor_bruto' => 999]), LogicException::class);
        $this->assertThrows(fn () => Venda::where('id', $venda->id)->delete(), LogicException::class);

        $this->assertEquals(100.00, $venda->fresh()->valor_bruto);
    }

    /**
     * Achado 4 (alto) — bovino-lab/spikes/007-concorrencia-real-mysql/run_correcao.php,
     * Ataque F. corrigir() não filtrava por status ao devolver animal pro
     * ativo — duas correções distintas (mesma Fazenda, mesma venda original)
     * que incluem o MESMO animal no conjunto "que sai" creditavam o lote
     * duas vezes pelo mesmo animal físico (violação de INV-001). Reproduzível
     * sem concorrência real — é bug de lógica, a corrida só o tornou óbvio.
     */
    public function test_duas_correcoes_da_mesma_venda_nao_creditam_o_mesmo_animal_duas_vezes_no_lote(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A'])->id;
        $usuario = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $usuario, 'fazenda_id' => $fazenda, 'papel' => 'dono']);

        $lote = Lote::create(['fazenda_id' => $fazenda, 'qtd_animais' => 5, 'custo_aquisicao' => 500])->id;
        $animais = [];
        for ($i = 0; $i < 5; $i++) {
            $animais[] = Animal::create(['fazenda_id' => $fazenda, 'lote_id' => $lote, 'status' => 'ativo'])->id;
        }
        [$a1, $a2, $a3, $a4, $a5] = $animais;

        $vendas = app(VendaService::class);
        $venda = $vendas->registrar($usuario, $fazenda, $animais, 500.00, 'venda-original');

        // Duas correções SEQUENCIAIS (nem precisa de corrida real pra provar
        // a lógica) pedindo a MESMA remoção — a5 sai nas duas.
        $vendas->corrigir($usuario, $venda['venda']->id, [$a1, $a2, $a3, $a4], 400.00, 'correcao-1');
        $vendas->corrigir($usuario, $venda['venda']->id, [$a1, $a2, $a3, $a4], 400.00, 'correcao-2');

        $loteDepois = Lote::find($lote);
        $this->assertSame(1, $loteDepois->qtd_animais, 'a5 é um único animal físico — só pode creditar o lote uma vez, mesmo com duas correções pedindo a mesma coisa');
        $this->assertEqualsWithDelta(100.00, (float) $loteDepois->custo_aquisicao, 0.01);
        $this->assertSame('ativo', Animal::find($a5)->status);
    }
}
