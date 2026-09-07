<?php

namespace Tests\Feature\VerticalNascimento;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\NascimentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 9 (Rebanho — Nascimento) — nasce de
 * VERTICAL-NASCIMENTO.md e SCHEMA-CONTRATO-NASCIMENTO.md. Reproduz
 * LAB-SA-006 (30 bezerros nascem em lote, sem mãe conhecida) e o caminho
 * individual (com mãe), unificados no mesmo fluxo.
 */
class NascimentoDominioTest extends TestCase
{
    use RefreshDatabase;

    private NascimentoService $nascimentos;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nascimentos = app(NascimentoService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
    }

    /** LAB-SA-006 — 30 bezerros nascem em lote, sem saber qual vaca pariu qual. */
    public function test_nascimento_em_lote_sem_mae_conhecida(): void
    {
        $filhotes = array_fill(0, 3, []);
        $registro = $this->nascimentos->registrar($this->jose, $this->fazenda, $filhotes, '2026-01-01 08:00:00', 'nascimento-lote-1');

        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertCount(3, $registro['nascimento']->animal_ids);

        foreach ($registro['nascimento']->animal_ids as $animalId) {
            $animal = Animal::find($animalId);
            $this->assertSame('ativo', $animal->status);
            $this->assertSame('nascido_na_fazenda', $animal->tipo_origem);
            $this->assertNull($animal->mae_id);
            $this->assertNull($animal->lote_id);
            $this->assertEqualsWithDelta(0.0, (float) $animal->custo_aquisicao, 0.01);
        }

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'nascimento_registrado')->first();
        $this->assertNotNull($evento);
    }

    /** Caminho individual, com mãe conhecida — mesmo fluxo, mae_id preenchido. */
    public function test_nascimento_individual_com_mae_conhecida(): void
    {
        $mae = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo'])->id;

        $registro = $this->nascimentos->registrar(
            $this->jose, $this->fazenda,
            [['mae_id' => $mae, 'peso_nascimento' => 34.2]],
            '2026-01-01 08:00:00', 'nascimento-individual-1'
        );

        $filhote = Animal::find($registro['nascimento']->animal_ids[0]);
        $this->assertSame($mae, $filhote->mae_id);
        $this->assertEqualsWithDelta(34.2, (float) $filhote->peso_nascimento, 0.01);
    }

    /** Os 2 caminhos no mesmo evento — mae_id sempre opcional por item, nunca bifurca o fluxo. */
    public function test_nascimento_mistura_com_e_sem_mae_no_mesmo_evento(): void
    {
        $mae = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo'])->id;

        $registro = $this->nascimentos->registrar(
            $this->jose, $this->fazenda,
            [['mae_id' => $mae], [], ['peso_nascimento' => 28.0]],
            '2026-01-01 08:00:00', 'nascimento-misto-1'
        );

        $this->assertCount(3, $registro['nascimento']->animal_ids);
        $filhotes = Animal::whereIn('id', $registro['nascimento']->animal_ids)->get();
        $this->assertSame(1, $filhotes->whereNotNull('mae_id')->count());
        $this->assertSame(1, $filhotes->whereNotNull('peso_nascimento')->count());
    }

    public function test_reenvio_do_registro_e_idempotente(): void
    {
        $primeiro = $this->nascimentos->registrar($this->jose, $this->fazenda, [[]], '2026-01-01 08:00:00', 'nascimento-mesma-chave');
        $segundo = $this->nascimentos->registrar($this->jose, $this->fazenda, [[]], '2026-01-01 08:00:00', 'nascimento-mesma-chave');

        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['nascimento']->id, $segundo['nascimento']->id);
        // Reenvio nunca cria um segundo Animal.
        $this->assertSame($primeiro['nascimento']->animal_ids, $segundo['nascimento']->animal_ids);
    }
}
