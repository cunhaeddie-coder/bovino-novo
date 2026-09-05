<?php

namespace Tests\Feature\VerticalMarketplace;

use App\Models\Animal;
use App\Models\Anuncio;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\NegociacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CicloIntegradoTest extends TestCase
{
    use RefreshDatabase;

    public function test_ciclo_completo_propor_aceitar_confirmar_e_visivel_pelos_2_lados(): void
    {
        $fazendaVendedora = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $fazendaCompradora = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $joao = Usuario::create(['nome' => 'João'])->id;
        $maria = Usuario::create(['nome' => 'Maria'])->id;
        Papel::create(['usuario_id' => $joao, 'fazenda_id' => $fazendaVendedora, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $maria, 'fazenda_id' => $fazendaCompradora, 'papel' => 'dono']);

        $anuncio = Anuncio::create([
            'fazenda_id' => $fazendaVendedora, 'preco_total' => 1200,
            'status' => 'ativo', 'publicado_em' => '2026-01-01',
        ]);
        $a1 = Animal::create(['fazenda_id' => $fazendaVendedora, 'lote_id' => null, 'custo_aquisicao' => 600, 'status' => 'ativo']);
        $a2 = Animal::create(['fazenda_id' => $fazendaVendedora, 'lote_id' => null, 'custo_aquisicao' => 600, 'status' => 'ativo']);
        $anuncio->animais()->attach([$a1->id, $a2->id]);

        $service = app(NegociacaoService::class);
        $negociacao = $service->propor($maria, $anuncio->id, $fazendaCompradora, 1200, 'ciclo-1')['negociacao'];
        $service->aceitar($joao, $negociacao->id);
        $service->confirmarVendedor($joao, $negociacao->id);
        $service->confirmarComprador($maria, $negociacao->id);

        $final = $negociacao->fresh();
        $this->assertSame('concluida', $final->status);
        $this->assertNotNull($final->venda_id);
        $this->assertNotNull($final->compra_id);
        $this->assertNotNull($final->concluida_em);

        // Visível pelos dois lados — vendedor e comprador.
        $this->assertNotNull($service->buscar($joao, $negociacao->id));
        $this->assertNotNull($service->buscar($maria, $negociacao->id));
    }

    public function test_reenvio_da_mesma_proposta_nao_cria_segunda_negociacao(): void
    {
        $fazendaVendedora = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $fazendaCompradora = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $maria = Usuario::create(['nome' => 'Maria'])->id;
        Papel::create(['usuario_id' => $maria, 'fazenda_id' => $fazendaCompradora, 'papel' => 'dono']);

        $anuncio = Anuncio::create([
            'fazenda_id' => $fazendaVendedora, 'preco_total' => 1000,
            'status' => 'ativo', 'publicado_em' => '2026-01-01',
        ]);
        $animal = Animal::create(['fazenda_id' => $fazendaVendedora, 'lote_id' => null, 'custo_aquisicao' => 1000, 'status' => 'ativo']);
        $anuncio->animais()->attach($animal->id);

        $service = app(NegociacaoService::class);
        $primeira = $service->propor($maria, $anuncio->id, $fazendaCompradora, 1000, 'mesma-chave');
        $segunda = $service->propor($maria, $anuncio->id, $fazendaCompradora, 1000, 'mesma-chave');

        $this->assertFalse($primeira['reenvio_detectado']);
        $this->assertTrue($segunda['reenvio_detectado']);
        $this->assertSame($primeira['negociacao']->id, $segunda['negociacao']->id);
    }
}
