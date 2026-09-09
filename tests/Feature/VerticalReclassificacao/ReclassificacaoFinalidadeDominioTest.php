<?php

namespace Tests\Feature\VerticalReclassificacao;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ReclassificacaoFinalidadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 16 (Reclassificação — finalidade) — nasce de
 * VERTICAL-RECLASSIFICACAO.md e SCHEMA-CONTRATO-RECLASSIFICACAO.md.
 * Reproduz LAB-FA-012 (140 fêmeas pra recria, 160 machos pra engorda, cada
 * grupo numa única operação, zero cruzamento errado).
 */
class ReclassificacaoFinalidadeDominioTest extends TestCase
{
    use RefreshDatabase;

    private ReclassificacaoFinalidadeService $reclassificacao;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reclassificacao = app(ReclassificacaoFinalidadeService::class);
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

    /** LAB-FA-012 — 140 recria + 160 engorda, zero cruzamento errado entre os dois grupos. */
    public function test_dois_grupos_reclassificados_sem_cruzamento(): void
    {
        $femeas = $this->criarAnimais(140);
        $machos = $this->criarAnimais(160);

        $this->reclassificacao->registrar($this->jose, $this->fazenda, $femeas, 'recria', 'femeas-recria');
        $this->reclassificacao->registrar($this->jose, $this->fazenda, $machos, 'engorda', 'machos-engorda');

        $this->assertSame('recria', Animal::find($femeas[0])->finalidade);
        $this->assertSame('engorda', Animal::find($machos[0])->finalidade);
        $this->assertSame(140, Animal::where('fazenda_id', $this->fazenda)->where('finalidade', 'recria')->count());
        $this->assertSame(160, Animal::where('fazenda_id', $this->fazenda)->where('finalidade', 'engorda')->count());

        $eventos = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'reclassificacao_finalidade_registrada')->count();
        $this->assertSame(2, $eventos);
    }

    public function test_reenvio_do_registro_e_idempotente(): void
    {
        $animalIds = $this->criarAnimais(3);
        $primeiro = $this->reclassificacao->registrar($this->jose, $this->fazenda, $animalIds, 'recria', 'mesma-chave');
        $segundo = $this->reclassificacao->registrar($this->jose, $this->fazenda, $animalIds, 'recria', 'mesma-chave');

        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['reclassificacao']->id, $segundo['reclassificacao']->id);
    }
}
