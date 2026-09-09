<?php

namespace Tests\Feature\VerticalReclassificacao;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ReclassificacaoCategoriaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 16 (Reclassificação — categoria) — nasce de
 * VERTICAL-RECLASSIFICACAO.md e SCHEMA-CONTRATO-RECLASSIFICACAO.md.
 * Reproduz LAB-FA-011 (desmama de 300 bezerros numa única operação, não 300
 * chamadas individuais — INV-010).
 */
class ReclassificacaoCategoriaDominioTest extends TestCase
{
    use RefreshDatabase;

    private ReclassificacaoCategoriaService $reclassificacao;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reclassificacao = app(ReclassificacaoCategoriaService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
    }

    private function criarAnimais(int $quantidade): array
    {
        return collect(range(1, $quantidade))
            ->map(fn () => Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 3000, 'status' => 'ativo'])->id)
            ->all();
    }

    /** LAB-FA-011 — 300 bezerros reclassificados numa única operação, não 300 chamadas. */
    public function test_reclassifica_muitos_animais_numa_unica_operacao(): void
    {
        $animalIds = $this->criarAnimais(300);

        $registro = $this->reclassificacao->registrar($this->jose, $this->fazenda, $animalIds, 'novilho', 'desmama-lote-1');

        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertCount(300, $registro['reclassificacao']->animal_ids);

        foreach (array_slice($animalIds, 0, 5) as $id) {
            $this->assertSame('novilho', Animal::find($id)->categoria);
        }

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'reclassificacao_categoria_registrada')->first();
        $this->assertNotNull($evento);
    }

    public function test_reenvio_do_registro_e_idempotente(): void
    {
        $animalIds = $this->criarAnimais(3);
        $primeiro = $this->reclassificacao->registrar($this->jose, $this->fazenda, $animalIds, 'novilho', 'mesma-chave');
        $segundo = $this->reclassificacao->registrar($this->jose, $this->fazenda, $animalIds, 'novilho', 'mesma-chave');

        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['reclassificacao']->id, $segundo['reclassificacao']->id);
    }

    public function test_animal_ja_vendido_nao_e_reclassificado_junto_com_os_ativos(): void
    {
        $ativos = $this->criarAnimais(2);
        $vendido = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 3000, 'status' => 'vendido'])->id;

        $this->assertThrows(
            fn () => $this->reclassificacao->registrar($this->jose, $this->fazenda, [...$ativos, $vendido], 'novilho', 'x'),
            \DomainException::class
        );

        $this->assertNull(Animal::find($ativos[0])->categoria);
    }
}
