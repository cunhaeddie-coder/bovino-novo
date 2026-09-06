<?php

namespace Tests\Feature\VerticalGta;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\GtaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 6 (GTA) — nasce de VERTICAL-GTA.md e
 * SCHEMA-CONTRATO-GTA.md. Reproduz LAB-FA-018 (GTA dos 40 bezerros vendidos).
 */
class GtaDominioTest extends TestCase
{
    use RefreshDatabase;

    private GtaService $gtas;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gtas = app(GtaService::class);
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

    /** LAB-FA-018 — GTA dos 40 bezerros (LAB-FA-014), R$112.000. */
    public function test_conclusao_da_gta_gera_venda_real_inv037(): void
    {
        $animais = $this->criarAnimais(3);
        $registro = $this->gtas->registrar($this->jose, $this->fazenda, $animais, 'Frigorífico Central', 3, 112000.00, '2026-01-01 08:00:00', 'gta-1');
        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertSame('emitida', $registro['gta']->status);

        $resultado = $this->gtas->concluir($this->jose, $registro['gta']->id);

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertSame('concluida', $resultado['gta']->status);
        $this->assertNotNull($resultado['gta']->venda_id);
        $this->assertNotNull($resultado['gta']->data_conclusao);

        $venda = $resultado['venda']->fresh();
        $this->assertEqualsWithDelta(112000.00, (float) $venda->valor_bruto, 0.01);
        $this->assertSame($animais, $venda->animal_ids);

        // INV-002/INV-003 — a Venda que nasce da GTA tem o mesmo núcleo
        // obrigatório de qualquer outra Venda: ObrigacaoFinanceira real.
        $obrigacao = ObrigacaoFinanceira::where('venda_id', $venda->id)->first();
        $this->assertNotNull($obrigacao, 'gta_gera_obrigacao_financeira_real_diferente_do_atual');
        $this->assertSame('a_receber', $obrigacao->direcao);

        // Todos os animais vendidos de verdade — não só "documentados".
        foreach ($animais as $animalId) {
            $this->assertSame('vendido', Animal::find($animalId)->status);
        }

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'gta_concluida')->first();
        $this->assertNotNull($evento);
    }

    public function test_concluir_duas_vezes_e_idempotente(): void
    {
        $animais = $this->criarAnimais(2);
        $registro = $this->gtas->registrar($this->jose, $this->fazenda, $animais, 'Frigorífico X', 2, 5000.00, '2026-01-01 08:00:00', 'gta-2');

        $primeira = $this->gtas->concluir($this->jose, $registro['gta']->id);
        $segunda = $this->gtas->concluir($this->jose, $registro['gta']->id);

        $this->assertTrue($segunda['reenvio_detectado']);
        $this->assertSame($primeira['venda']->id, $segunda['gta']->venda_id, 'nao_criou_segunda_venda');
    }

    public function test_reenvio_do_registro_e_idempotente(): void
    {
        $animais = $this->criarAnimais(1);
        $primeiro = $this->gtas->registrar($this->jose, $this->fazenda, $animais, 'X', 1, 1000.00, '2026-01-01 08:00:00', 'gta-mesma-chave');
        $segundo = $this->gtas->registrar($this->jose, $this->fazenda, $animais, 'X', 1, 1000.00, '2026-01-01 08:00:00', 'gta-mesma-chave');

        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['gta']->id, $segundo['gta']->id);
    }
}
