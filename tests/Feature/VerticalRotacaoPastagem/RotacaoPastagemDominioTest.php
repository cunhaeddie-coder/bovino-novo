<?php

namespace Tests\Feature\VerticalRotacaoPastagem;

use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Lote;
use App\Models\Papel;
use App\Models\Piquete;
use App\Models\Usuario;
use App\Services\RotacaoPastagemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 15 (Rotação de Pastagem) — nasce de
 * VERTICAL-ROTACAO-PASTAGEM.md e SCHEMA-CONTRATO-ROTACAO-PASTAGEM.md.
 * Reproduz LAB-FA-017 (reuso prematuro de uma área de descanso aceito
 * exatamente igual a um ciclo correto) e corrige de verdade: toda troca
 * agora grava explicitamente se o descanso foi cumprido (INV-040).
 */
class RotacaoPastagemDominioTest extends TestCase
{
    use RefreshDatabase;

    private RotacaoPastagemService $rotacao;

    private int $fazenda;

    private int $jose;

    private int $lote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rotacao = app(RotacaoPastagemService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->lote = Lote::create(['fazenda_id' => $this->fazenda, 'qtd_animais' => 20, 'custo_aquisicao' => 40000])->id;
    }

    /** Primeira alocação de um Lote nunca teve Piquete de origem — descanso nunca pode estar interrompido. */
    public function test_primeira_alocacao_nunca_marca_descanso_interrompido(): void
    {
        $registro = $this->rotacao->registrar($this->jose, $this->fazenda, $this->lote, null, null, ['nome' => 'Piquete 1', 'dias_descanso' => 20], '2026-01-01 08:00:00', 'troca-1');

        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertFalse($registro['troca']->descanso_interrompido);

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'troca_piquete_registrada')->first();
        $this->assertNotNull($evento);
    }

    /** LAB-FA-017 — reuso prematuro (5 de 18 dias) precisa ficar marcado, nunca silenciosamente igual ao ciclo correto. */
    public function test_reuso_prematuro_marca_descanso_interrompido_inv040(): void
    {
        $piqueteA = Piquete::create(['fazenda_id' => $this->fazenda, 'nome' => 'Piquete A', 'dias_descanso' => 18]);
        $piqueteB = Piquete::create(['fazenda_id' => $this->fazenda, 'nome' => 'Piquete B', 'dias_descanso' => 18]);

        // Lote sai do Piquete A em 01/01.
        $this->rotacao->registrar($this->jose, $this->fazenda, $this->lote, null, $piqueteA->id, null, '2026-01-01 08:00:00', 'entra-a');
        $this->rotacao->registrar($this->jose, $this->fazenda, $this->lote, $piqueteA->id, $piqueteB->id, null, '2026-01-01 08:00:01', 'sai-a');

        // Só 5 dias depois (18 exigidos), outro Lote volta pro Piquete A.
        $outroLote = Lote::create(['fazenda_id' => $this->fazenda, 'qtd_animais' => 10, 'custo_aquisicao' => 20000])->id;
        $registro = $this->rotacao->registrar($this->jose, $this->fazenda, $outroLote, $piqueteB->id, $piqueteA->id, null, '2026-01-06 08:00:00', 'reuso-prematuro');

        $this->assertTrue($registro['troca']->descanso_interrompido);
    }

    /** Ciclo correto (19 de 18 dias exigidos) nunca marca descanso interrompido. */
    public function test_ciclo_correto_nao_marca_descanso_interrompido(): void
    {
        $piqueteA = Piquete::create(['fazenda_id' => $this->fazenda, 'nome' => 'Piquete A', 'dias_descanso' => 18]);
        $piqueteB = Piquete::create(['fazenda_id' => $this->fazenda, 'nome' => 'Piquete B', 'dias_descanso' => 18]);

        $this->rotacao->registrar($this->jose, $this->fazenda, $this->lote, null, $piqueteA->id, null, '2026-01-01 08:00:00', 'entra-a');
        $this->rotacao->registrar($this->jose, $this->fazenda, $this->lote, $piqueteA->id, $piqueteB->id, null, '2026-01-01 08:00:01', 'sai-a');

        $outroLote = Lote::create(['fazenda_id' => $this->fazenda, 'qtd_animais' => 10, 'custo_aquisicao' => 20000])->id;
        $registro = $this->rotacao->registrar($this->jose, $this->fazenda, $outroLote, $piqueteB->id, $piqueteA->id, null, '2026-01-20 08:00:00', 'ciclo-correto');

        $this->assertFalse($registro['troca']->descanso_interrompido);
    }

    public function test_reenvio_do_registro_e_idempotente(): void
    {
        $primeiro = $this->rotacao->registrar($this->jose, $this->fazenda, $this->lote, null, null, ['nome' => 'Piquete 1', 'dias_descanso' => 20], '2026-01-01 08:00:00', 'mesma-chave');
        $segundo = $this->rotacao->registrar($this->jose, $this->fazenda, $this->lote, null, null, ['nome' => 'Piquete 1', 'dias_descanso' => 20], '2026-01-01 08:00:00', 'mesma-chave');

        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['troca']->id, $segundo['troca']->id);
    }
}
