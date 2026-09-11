<?php

namespace Tests\Feature\VerticalMarcacaoCioPrenhez;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ConfirmacaoPrenhezService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 17 (Confirmação de Prenhez) — nasce de
 * VERTICAL-MARCACAO-CIO-PRENHEZ.md e SCHEMA-CONTRATO-MARCACAO-CIO-PRENHEZ.md.
 * Segundo dos 2 pontos de ancoragem que faltavam no Histórico Reprodutivo.
 */
class ConfirmacaoPrenhezDominioTest extends TestCase
{
    use RefreshDatabase;

    private ConfirmacaoPrenhezService $confirmacoes;

    private int $fazenda;

    private int $jose;

    private int $vaca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->confirmacoes = app(ConfirmacaoPrenhezService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->vaca = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo'])->id;
    }

    /** Duração de gestação bovina padrão: 283 dias a partir da confirmação. */
    public function test_resultado_positivo_calcula_data_parto_estimada(): void
    {
        $registro = $this->confirmacoes->registrar($this->jose, $this->fazenda, $this->vaca, 'positivo', 'ultrassonografia', '2026-01-01 08:00:00', 'confirmacao-1');

        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertSame('2026-10-11', $registro['confirmacao']->data_parto_estimada->toDateString());

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'confirmacao_prenhez_registrada')->first();
        $this->assertNotNull($evento);
    }

    public function test_resultado_negativo_nao_calcula_data_parto_estimada(): void
    {
        $registro = $this->confirmacoes->registrar($this->jose, $this->fazenda, $this->vaca, 'negativo', 'ultrassonografia', '2026-01-01 08:00:00', 'confirmacao-2');

        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertNull($registro['confirmacao']->data_parto_estimada);
    }

    public function test_mesma_vaca_confirmada_mais_de_uma_vez_na_vida_e_permitido(): void
    {
        $primeira = $this->confirmacoes->registrar($this->jose, $this->fazenda, $this->vaca, 'negativo', 'ultrassonografia', '2026-01-01 08:00:00', 'confirmacao-a');
        $segunda = $this->confirmacoes->registrar($this->jose, $this->fazenda, $this->vaca, 'positivo', 'ultrassonografia', '2026-06-01 08:00:00', 'confirmacao-b');

        $this->assertFalse($primeira['reenvio_detectado']);
        $this->assertFalse($segunda['reenvio_detectado']);
        $this->assertNotSame($primeira['confirmacao']->id, $segunda['confirmacao']->id);
    }

    public function test_reenvio_do_registro_e_idempotente(): void
    {
        $primeiro = $this->confirmacoes->registrar($this->jose, $this->fazenda, $this->vaca, 'positivo', 'ultrassonografia', '2026-01-01 08:00:00', 'mesma-chave');
        $segundo = $this->confirmacoes->registrar($this->jose, $this->fazenda, $this->vaca, 'positivo', 'ultrassonografia', '2026-01-01 08:00:00', 'mesma-chave');

        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['confirmacao']->id, $segundo['confirmacao']->id);
    }
}
