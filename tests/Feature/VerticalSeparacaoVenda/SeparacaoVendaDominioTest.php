<?php

namespace Tests\Feature\VerticalSeparacaoVenda;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\SeparacaoVendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 8 (Separação para Venda) — nasce de
 * VERTICAL-SEPARACAO-VENDA.md e SCHEMA-CONTRATO-SEPARACAO-VENDA.md.
 * Reproduz LAB-FA-014 (separação de 40 bezerros pra venda direta, R$112.000
 * que desapareciam sem deixar rastro no Atual).
 */
class SeparacaoVendaDominioTest extends TestCase
{
    use RefreshDatabase;

    private SeparacaoVendaService $separacoes;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->separacoes = app(SeparacaoVendaService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
    }

    private function criarAnimais(int $quantidade): array
    {
        return collect(range(1, $quantidade))
            ->map(fn () => Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id)
            ->all();
    }

    /** LAB-FA-014 — separação dos 40 bezerros pra venda direta, R$112.000. */
    public function test_conclusao_da_separacao_gera_venda_real(): void
    {
        $animais = $this->criarAnimais(3);
        $registro = $this->separacoes->registrar($this->jose, $this->fazenda, $animais, 112000.00, '2026-01-01 08:00:00', 'separacao-1');
        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertSame('aberta', $registro['separacao']->status);

        $resultado = $this->separacoes->concluir($this->jose, $registro['separacao']->id);

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertSame('concluida', $resultado['separacao']->status);
        $this->assertNotNull($resultado['separacao']->venda_id);
        $this->assertNotNull($resultado['separacao']->data_conclusao);

        $venda = $resultado['venda']->fresh();
        $this->assertEqualsWithDelta(112000.00, (float) $venda->valor_bruto, 0.01);
        $this->assertSame($animais, $venda->animal_ids);

        // INV-002/INV-003 — a Venda que nasce da separação tem o mesmo
        // núcleo obrigatório de qualquer outra Venda: ObrigacaoFinanceira real.
        $obrigacao = ObrigacaoFinanceira::where('venda_id', $venda->id)->first();
        $this->assertNotNull($obrigacao, 'separacao_gera_obrigacao_financeira_real_diferente_do_atual');
        $this->assertSame('a_receber', $obrigacao->direcao);

        // Todos os animais vendidos de verdade — não só "documentados".
        foreach ($animais as $animalId) {
            $this->assertSame('vendido', Animal::find($animalId)->status);
        }

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'separacao_venda_concluida')->first();
        $this->assertNotNull($evento);
    }

    public function test_concluir_duas_vezes_e_idempotente(): void
    {
        $animais = $this->criarAnimais(2);
        $registro = $this->separacoes->registrar($this->jose, $this->fazenda, $animais, 5000.00, '2026-01-01 08:00:00', 'separacao-2');

        $primeira = $this->separacoes->concluir($this->jose, $registro['separacao']->id);
        $segunda = $this->separacoes->concluir($this->jose, $registro['separacao']->id);

        $this->assertTrue($segunda['reenvio_detectado']);
        $this->assertSame($primeira['venda']->id, $segunda['separacao']->venda_id, 'nao_criou_segunda_venda');
    }

    public function test_reenvio_do_registro_e_idempotente(): void
    {
        $animais = $this->criarAnimais(1);
        $primeiro = $this->separacoes->registrar($this->jose, $this->fazenda, $animais, 1000.00, '2026-01-01 08:00:00', 'separacao-mesma-chave');
        $segundo = $this->separacoes->registrar($this->jose, $this->fazenda, $animais, 1000.00, '2026-01-01 08:00:00', 'separacao-mesma-chave');

        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['separacao']->id, $segundo['separacao']->id);
    }
}
