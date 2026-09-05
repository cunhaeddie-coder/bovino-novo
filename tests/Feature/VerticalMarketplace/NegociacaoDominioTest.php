<?php

namespace Tests\Feature\VerticalMarketplace;

use App\Models\Animal;
use App\Models\Anuncio;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\NegociacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O caminho feliz do vertical Marketplace: propor → aceitar → confirmar nos
 * 2 lados, em qualquer ordem — VERTICAL-MARKETPLACE.md/SCHEMA-CONTRATO-MARKETPLACE.md.
 */
class NegociacaoDominioTest extends TestCase
{
    use RefreshDatabase;

    private int $fazendaVendedora;

    private int $fazendaCompradora;

    private int $joao; // vendedor

    private int $maria; // compradora

    private Anuncio $anuncio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fazendaVendedora = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->fazendaCompradora = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $this->joao = Usuario::create(['nome' => 'João'])->id;
        $this->maria = Usuario::create(['nome' => 'Maria'])->id;
        Papel::create(['usuario_id' => $this->joao, 'fazenda_id' => $this->fazendaVendedora, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $this->maria, 'fazenda_id' => $this->fazendaCompradora, 'papel' => 'dono']);

        $this->anuncio = Anuncio::create([
            'fazenda_id' => $this->fazendaVendedora, 'preco_total' => 1000,
            'status' => 'ativo', 'publicado_em' => '2026-01-01',
        ]);
        $animal1 = Animal::create(['fazenda_id' => $this->fazendaVendedora, 'lote_id' => null, 'custo_aquisicao' => 300, 'status' => 'ativo']);
        $animal2 = Animal::create(['fazenda_id' => $this->fazendaVendedora, 'lote_id' => null, 'custo_aquisicao' => 300, 'status' => 'ativo']);
        $animal3 = Animal::create(['fazenda_id' => $this->fazendaVendedora, 'lote_id' => null, 'custo_aquisicao' => 300, 'status' => 'ativo']);
        $this->anuncio->animais()->attach([$animal1->id, $animal2->id, $animal3->id]);
    }

    private function propor(NegociacaoService $service, string $chave = 'neg-1'): array
    {
        return $service->propor($this->maria, $this->anuncio->id, $this->fazendaCompradora, 1000, $chave);
    }

    public function test_vendedor_confirma_primeiro(): void
    {
        $service = app(NegociacaoService::class);
        $negociacao = $this->propor($service)['negociacao'];
        $service->aceitar($this->joao, $negociacao->id);

        $resultadoVendedor = $service->confirmarVendedor($this->joao, $negociacao->id);
        $this->assertFalse($resultadoVendedor['reenvio_detectado']);
        $this->assertSame('aceita', $resultadoVendedor['negociacao']->status);
        $this->assertNotNull($resultadoVendedor['negociacao']->venda_id);
        $this->assertSame('vendido', $this->anuncio->fresh()->status, 'inv023_anuncio_encerra_na_confirmacao_do_vendedor');

        $resultadoComprador = $service->confirmarComprador($this->maria, $negociacao->id);
        $this->assertSame('concluida', $resultadoComprador['negociacao']->status);
        $this->assertNotNull($resultadoComprador['negociacao']->compra_id);
        $this->assertNotNull($resultadoComprador['negociacao']->concluida_em);
    }

    public function test_comprador_confirma_primeiro(): void
    {
        $service = app(NegociacaoService::class);
        $negociacao = $this->propor($service)['negociacao'];
        $service->aceitar($this->joao, $negociacao->id);

        $resultadoComprador = $service->confirmarComprador($this->maria, $negociacao->id);
        $this->assertSame('aceita', $resultadoComprador['negociacao']->status, 'ainda_nao_concluida_so_1_lado_confirmou');

        $resultadoVendedor = $service->confirmarVendedor($this->joao, $negociacao->id);
        $this->assertSame('concluida', $resultadoVendedor['negociacao']->status);
    }

    public function test_confirmacao_dupla_e_idempotente(): void
    {
        $service = app(NegociacaoService::class);
        $negociacao = $this->propor($service)['negociacao'];
        $service->aceitar($this->joao, $negociacao->id);

        $primeira = $service->confirmarVendedor($this->joao, $negociacao->id);
        $segunda = $service->confirmarVendedor($this->joao, $negociacao->id);

        $this->assertTrue($segunda['reenvio_detectado']);
        $this->assertSame($primeira['venda']->id, $negociacao->fresh()->venda_id, 'nao_criou_segunda_venda');
    }

    public function test_fornecedor_da_fazenda_vendedora_e_reaproveitado_entre_negociacoes(): void
    {
        $service = app(NegociacaoService::class);

        $negociacao1 = $this->propor($service, 'neg-1')['negociacao'];
        $service->aceitar($this->joao, $negociacao1->id);
        $service->confirmarVendedor($this->joao, $negociacao1->id);
        $service->confirmarComprador($this->maria, $negociacao1->id);

        $this->assertSame(1, Fornecedor::where('fazenda_id', $this->fazendaVendedora)->count());

        // Segundo Anúncio/Negociação entre as mesmas 2 Fazendas — mesmo Fornecedor.
        $animal4 = Animal::create(['fazenda_id' => $this->fazendaVendedora, 'lote_id' => null, 'custo_aquisicao' => 100, 'status' => 'ativo']);
        $anuncio2 = Anuncio::create([
            'fazenda_id' => $this->fazendaVendedora, 'preco_total' => 500,
            'status' => 'ativo', 'publicado_em' => '2026-01-02',
        ]);
        $anuncio2->animais()->attach($animal4->id);

        $negociacao2 = $service->propor($this->maria, $anuncio2->id, $this->fazendaCompradora, 500, 'neg-2')['negociacao'];
        $service->aceitar($this->joao, $negociacao2->id);
        $service->confirmarVendedor($this->joao, $negociacao2->id);
        $service->confirmarComprador($this->maria, $negociacao2->id);

        $this->assertSame(1, Fornecedor::where('fazenda_id', $this->fazendaVendedora)->count(), 'nao_duplicou_fornecedor');
    }

    public function test_divisao_de_preco_bate_exato_com_o_total_mesmo_sem_dividir_igual(): void
    {
        // 3 animais, R$1000 — não divide igual (333,33 x 3 = 999,99).
        $service = app(NegociacaoService::class);
        $negociacao = $this->propor($service)['negociacao'];
        $service->aceitar($this->joao, $negociacao->id);
        $service->confirmarVendedor($this->joao, $negociacao->id);
        $resultado = $service->confirmarComprador($this->maria, $negociacao->id);

        $compra = $resultado['compra']->fresh();
        $this->assertEqualsWithDelta(1000.00, (float) $compra->valor_total, 0.001, 'soma_bate_exato_com_preco_proposto');
    }
}
