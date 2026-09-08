<?php

namespace Tests\Feature\VerticalProducaoLeiteira;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ProducaoLeiteiraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 14 (Produção Leiteira) — nasce de
 * VERTICAL-PRODUCAO-LEITEIRA.md e SCHEMA-CONTRATO-PRODUCAO-LEITEIRA.md.
 * Reproduz LAB-SA-020 (produção diária de leite, rastreável por vaca,
 * distinguindo litro vendido de litro pro bezerro — o diferencial do
 * módulo v2 que o Atual escondia atrás de um plano pago).
 */
class ProducaoLeiteiraDominioTest extends TestCase
{
    use RefreshDatabase;

    private ProducaoLeiteiraService $producoes;

    private int $fazenda;

    private int $jose;

    private int $vaca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->producoes = app(ProducaoLeiteiraService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->vaca = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo'])->id;
    }

    /** LAB-SA-020 — produção rastreável por vaca, litro vendido separado do litro pro bezerro. */
    public function test_registro_de_producao_distingue_vendido_de_bezerro(): void
    {
        $registro = $this->producoes->registrar($this->jose, $this->fazenda, $this->vaca, '2026-01-01 18:00:00', 10.0, 7.0, 3.0, 'producao-1');

        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertSame($this->vaca, $registro['producao']->animal_id);
        $this->assertEqualsWithDelta(10.0, (float) $registro['producao']->quantidade_total, 0.01);
        $this->assertEqualsWithDelta(7.0, (float) $registro['producao']->quantidade_vendida, 0.01);
        $this->assertEqualsWithDelta(3.0, (float) $registro['producao']->quantidade_bezerro, 0.01);

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'producao_leiteira_registrada')->first();
        $this->assertNotNull($evento);
    }

    public function test_producao_sem_venda_e_valida_zero_e_valor_aceito(): void
    {
        $registro = $this->producoes->registrar($this->jose, $this->fazenda, $this->vaca, '2026-01-01 18:00:00', 8.0, 0.0, 8.0, 'producao-2');
        $this->assertFalse($registro['reenvio_detectado']);
    }

    public function test_duas_producoes_no_mesmo_dia_sao_permitidas_chaves_diferentes(): void
    {
        $manha = $this->producoes->registrar($this->jose, $this->fazenda, $this->vaca, '2026-01-01 06:00:00', 5.0, 3.0, 2.0, 'producao-manha');
        $tarde = $this->producoes->registrar($this->jose, $this->fazenda, $this->vaca, '2026-01-01 18:00:00', 5.0, 4.0, 1.0, 'producao-tarde');

        $this->assertFalse($manha['reenvio_detectado']);
        $this->assertFalse($tarde['reenvio_detectado']);
        $this->assertNotSame($manha['producao']->id, $tarde['producao']->id);
    }

    public function test_reenvio_do_registro_e_idempotente(): void
    {
        $primeiro = $this->producoes->registrar($this->jose, $this->fazenda, $this->vaca, '2026-01-01 18:00:00', 10.0, 6.0, 4.0, 'producao-mesma-chave');
        $segundo = $this->producoes->registrar($this->jose, $this->fazenda, $this->vaca, '2026-01-01 18:00:00', 10.0, 6.0, 4.0, 'producao-mesma-chave');

        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['producao']->id, $segundo['producao']->id);
    }
}
