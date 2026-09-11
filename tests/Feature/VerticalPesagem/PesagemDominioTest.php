<?php

namespace Tests\Feature\VerticalPesagem;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Pesagem;
use App\Models\Usuario;
use App\Services\PesagemService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 19 (Pesagem) — nasce de VERTICAL-PESAGEM.md
 * e SCHEMA-CONTRATO-PESAGEM.md. Reproduz LAB-FA-013 (pesagem em lote real,
 * 160 animais, GMD correto — aqui só a escrita, GMD é consulta fora de
 * escopo).
 */
class PesagemDominioTest extends TestCase
{
    use RefreshDatabase;

    private PesagemService $pesagens;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pesagens = app(PesagemService::class);
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

    public function test_registro_individual_grava_o_peso_do_animal(): void
    {
        [$vaca] = $this->criarAnimais(1);
        $registro = $this->pesagens->registrar($this->jose, $this->fazenda, $vaca, 280.0, '2026-01-01 08:00:00', 'pesagem-1');

        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertEqualsWithDelta(280.0, (float) $registro['pesagem']->peso, 0.01);

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'pesagem_registrada')->first();
        $this->assertNotNull($evento);
    }

    public function test_reenvio_do_registro_individual_e_idempotente(): void
    {
        [$vaca] = $this->criarAnimais(1);
        $primeiro = $this->pesagens->registrar($this->jose, $this->fazenda, $vaca, 280.0, '2026-01-01 08:00:00', 'mesma-chave');
        $segundo = $this->pesagens->registrar($this->jose, $this->fazenda, $vaca, 280.0, '2026-01-01 08:00:00', 'mesma-chave');

        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['pesagem']->id, $segundo['pesagem']->id);
        $this->assertSame(1, Pesagem::where('fazenda_id', $this->fazenda)->count());
    }

    /** LAB-FA-013 — lote real: 160 animais numa chamada só, cada um com seu próprio peso. */
    public function test_registro_em_lote_grava_um_peso_diferente_por_animal(): void
    {
        $animais = $this->criarAnimais(3);
        $pesos = [
            ['animal_id' => $animais[0], 'peso' => 195.0, 'chave_idempotencia' => 'lote-1-a'],
            ['animal_id' => $animais[1], 'peso' => 201.5, 'chave_idempotencia' => 'lote-1-b'],
            ['animal_id' => $animais[2], 'peso' => 188.2, 'chave_idempotencia' => 'lote-1-c'],
        ];

        $resultados = $this->pesagens->registrarLote($this->jose, $this->fazenda, $pesos, '2026-01-01 08:00:00');

        $this->assertCount(3, $resultados);
        $this->assertSame(3, Pesagem::where('fazenda_id', $this->fazenda)->count());
        $this->assertEqualsWithDelta(195.0, (float) Pesagem::where('animal_id', $animais[0])->first()->peso, 0.01);
        $this->assertEqualsWithDelta(201.5, (float) Pesagem::where('animal_id', $animais[1])->first()->peso, 0.01);
        $this->assertEqualsWithDelta(188.2, (float) Pesagem::where('animal_id', $animais[2])->first()->peso, 0.01);
    }

    /** Validação-antes-de-criar (mesma disciplina de Nascimento): nenhum item criado se um animal for inválido. */
    public function test_lote_com_um_animal_invalido_nao_cria_nenhuma_pesagem(): void
    {
        [$valido] = $this->criarAnimais(1);
        $outraFazenda = Fazenda::create(['nome' => 'B'])->id;
        $invalido = Animal::create(['fazenda_id' => $outraFazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id;

        $pesos = [
            ['animal_id' => $valido, 'peso' => 195.0, 'chave_idempotencia' => 'lote-2-a'],
            ['animal_id' => $invalido, 'peso' => 200.0, 'chave_idempotencia' => 'lote-2-b'],
        ];

        $this->assertThrows(
            fn () => $this->pesagens->registrarLote($this->jose, $this->fazenda, $pesos, '2026-01-01 08:00:00'),
            DomainException::class
        );
        $this->assertSame(0, Pesagem::where('fazenda_id', $this->fazenda)->count());
    }

    /** Reenvio do lote inteiro é idempotente item a item, não como bloco único. */
    public function test_reenvio_do_lote_inteiro_e_idempotente_por_animal(): void
    {
        $animais = $this->criarAnimais(2);
        $pesos = [
            ['animal_id' => $animais[0], 'peso' => 195.0, 'chave_idempotencia' => 'lote-3-a'],
            ['animal_id' => $animais[1], 'peso' => 201.5, 'chave_idempotencia' => 'lote-3-b'],
        ];

        $this->pesagens->registrarLote($this->jose, $this->fazenda, $pesos, '2026-01-01 08:00:00');
        $reenvio = $this->pesagens->registrarLote($this->jose, $this->fazenda, $pesos, '2026-01-01 08:00:00');

        $this->assertTrue($reenvio[0]['reenvio_detectado']);
        $this->assertTrue($reenvio[1]['reenvio_detectado']);
        $this->assertSame(2, Pesagem::where('fazenda_id', $this->fazenda)->count());
    }
}
