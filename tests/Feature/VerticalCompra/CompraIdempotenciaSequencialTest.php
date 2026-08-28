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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Idempotência sequencial de CompraService::registrar() — a forma exata do
 * contrato, antes de qualquer concorrência real. Lógica de domínio, não
 * depende de mecanismo de banco (Princípio 4b não se aplica aqui) — SQLite
 * é ambiente adequado pra esta pergunta especificamente.
 *
 * Não basta provar que o reenvio não duplica — precisa provar que a
 * PRIMEIRA execução já deixou o estado completo (Compra + itens + Animais +
 * obrigação + evento). Uma implementação poderia esconder uma gravação
 * parcial atrás de uma resposta aparentemente idempotente.
 */
class CompraIdempotenciaSequencialTest extends TestCase
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

    public function test_primeira_execucao_deixa_estado_completo(): void
    {
        $r1 = $this->compras->registrar($this->jose, $this->fazenda, $this->marilia, [5000.00, 6000.00], '2026-03-01', 'compra-x');

        $this->assertFalse($r1['reenvio_detectado']);

        $compra = $r1['compra'];
        $this->assertNotNull($compra->id, 'compra_existe');
        $this->assertEqualsWithDelta(11000.00, (float) $compra->fresh()->valor_total, 0.01, 'valor_total_completo');

        $this->assertSame(2, CompraItem::where('compra_id', $compra->id)->count(), 'dois_itens_completos');
        $this->assertSame(2, Animal::where('fazenda_id', $this->fazenda)->count(), 'dois_animais_completos');
        $this->assertSame(1, ObrigacaoFinanceira::where('compra_id', $compra->id)->count(), 'uma_obrigacao_completa');
        $this->assertEqualsWithDelta(11000.00, (float) ObrigacaoFinanceira::where('compra_id', $compra->id)->first()->valor, 0.01, 'obrigacao_valor_completo');
        $this->assertSame(1, EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'compra_concluida')->count(), 'um_evento_completo');

        // Nenhum animal criado sem custo — sinal de gravação parcial escondida atrás de uma resposta aparentemente boa.
        foreach (Animal::where('fazenda_id', $this->fazenda)->get() as $animal) {
            $this->assertNotNull($animal->custo_aquisicao, 'nenhum_animal_com_custo_nulo');
        }
    }

    public function test_reenvio_sequencial_nao_duplica_nada(): void
    {
        $r1 = $this->compras->registrar($this->jose, $this->fazenda, $this->marilia, [5000.00, 6000.00], '2026-03-01', 'compra-x');
        $r2 = $this->compras->registrar($this->jose, $this->fazenda, $this->marilia, [5000.00, 6000.00], '2026-03-01', 'compra-x');
        $r3 = $this->compras->registrar($this->jose, $this->fazenda, $this->marilia, [5000.00, 6000.00], '2026-03-01', 'compra-x');

        $this->assertFalse($r1['reenvio_detectado']);
        $this->assertTrue($r2['reenvio_detectado'], 'segunda_chamada_reenvio');
        $this->assertTrue($r3['reenvio_detectado'], 'terceira_chamada_reenvio');
        $this->assertSame($r1['compra']->id, $r2['compra']->id);
        $this->assertSame($r1['compra']->id, $r3['compra']->id);

        $this->assertSame(1, Compra::where('fazenda_id', $this->fazenda)->count(), 'uma_unica_compra_apos_3_tentativas');
        $this->assertSame(2, Animal::where('fazenda_id', $this->fazenda)->count(), 'animais_nunca_duplicados');
        $this->assertSame(2, CompraItem::count(), 'itens_nunca_duplicados');
        $this->assertSame(1, ObrigacaoFinanceira::where('compra_id', $r1['compra']->id)->count(), 'obrigacao_nunca_duplicada');
        $this->assertSame(1, EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'compra_concluida')->count(), 'evento_nunca_duplicado');
        // Revisão de fronteira (27/08/2026) — faltava confirmar que o valor
        // permanece intocado, não só que a contagem de linhas não muda.
        $this->assertEqualsWithDelta(11000.00, (float) $r1['compra']->fresh()->valor_total, 0.01, 'valor_total_intocado_apos_reenvios');
    }
}
