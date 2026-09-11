<?php

namespace Tests\Feature\VerticalMarcacaoCioPrenhez;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\MarcacaoCioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 17 (Marcação de Cio) — nasce de
 * VERTICAL-MARCACAO-CIO-PRENHEZ.md e SCHEMA-CONTRATO-MARCACAO-CIO-PRENHEZ.md.
 * Primeiro dos 2 pontos de ancoragem que faltavam no Histórico Reprodutivo.
 */
class MarcacaoCioDominioTest extends TestCase
{
    use RefreshDatabase;

    private MarcacaoCioService $marcacoes;

    private int $fazenda;

    private int $jose;

    private int $vaca;

    private int $rufiao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marcacoes = app(MarcacaoCioService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->vaca = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo'])->id;
        $this->rufiao = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 8000, 'status' => 'ativo', 'finalidade' => 'rufião'])->id;
    }

    public function test_registro_de_marcacao_de_cio(): void
    {
        $registro = $this->marcacoes->registrar($this->jose, $this->fazenda, $this->vaca, $this->rufiao, '2026-01-01 06:00:00', 'marcacao-1');

        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertSame($this->vaca, $registro['marcacao']->vaca_id);
        $this->assertSame($this->rufiao, $registro['marcacao']->rufiao_id);

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'marcacao_cio_registrada')->first();
        $this->assertNotNull($evento);
    }

    public function test_mesma_vaca_marcada_mais_de_uma_vez_e_permitido(): void
    {
        $primeira = $this->marcacoes->registrar($this->jose, $this->fazenda, $this->vaca, $this->rufiao, '2026-01-01 06:00:00', 'marcacao-a');
        $segunda = $this->marcacoes->registrar($this->jose, $this->fazenda, $this->vaca, $this->rufiao, '2026-01-22 06:00:00', 'marcacao-b');

        $this->assertFalse($primeira['reenvio_detectado']);
        $this->assertFalse($segunda['reenvio_detectado']);
        $this->assertNotSame($primeira['marcacao']->id, $segunda['marcacao']->id);
    }

    public function test_reenvio_do_registro_e_idempotente(): void
    {
        $primeiro = $this->marcacoes->registrar($this->jose, $this->fazenda, $this->vaca, $this->rufiao, '2026-01-01 06:00:00', 'mesma-chave');
        $segundo = $this->marcacoes->registrar($this->jose, $this->fazenda, $this->vaca, $this->rufiao, '2026-01-01 06:00:00', 'mesma-chave');

        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['marcacao']->id, $segundo['marcacao']->id);
    }

    public function test_rufiao_sem_finalidade_registrada_ainda_e_aceito(): void
    {
        // SCHEMA-CONTRATO-MARCACAO-CIO-PRENHEZ.md §2 — sem checagem de
        // finalidade='rufião', decisão registrada explicitamente.
        $outroAnimal = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 3000, 'status' => 'ativo'])->id;

        $registro = $this->marcacoes->registrar($this->jose, $this->fazenda, $this->vaca, $outroAnimal, '2026-01-01 06:00:00', 'marcacao-sem-finalidade');

        $this->assertFalse($registro['reenvio_detectado']);
    }
}
