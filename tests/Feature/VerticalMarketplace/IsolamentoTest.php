<?php

namespace Tests\Feature\VerticalMarketplace;

use App\Models\Animal;
use App\Models\Anuncio;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\NegociacaoService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INV-029 — nenhum usuário sem relação com uma das 2 Fazendas envolvidas
 * (vendedora ou compradora) enxerga ou altera uma Negociação/Anúncio.
 */
class IsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_de_terceira_fazenda_nao_ve_a_negociacao(): void
    {
        $fazendaVendedora = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $fazendaCompradora = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $terceiraFazenda = Fazenda::create(['nome' => 'Outra Fazenda'])->id;
        $maria = Usuario::create(['nome' => 'Maria'])->id;
        $intruso = Usuario::create(['nome' => 'Intruso'])->id;
        Papel::create(['usuario_id' => $maria, 'fazenda_id' => $fazendaCompradora, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $intruso, 'fazenda_id' => $terceiraFazenda, 'papel' => 'dono']);

        $anuncio = Anuncio::create([
            'fazenda_id' => $fazendaVendedora, 'preco_total' => 1000,
            'status' => 'ativo', 'publicado_em' => '2026-01-01',
        ]);
        $animal = Animal::create(['fazenda_id' => $fazendaVendedora, 'lote_id' => null, 'custo_aquisicao' => 1000, 'status' => 'ativo']);
        $anuncio->animais()->attach($animal->id);

        $service = app(NegociacaoService::class);
        $negociacao = $service->propor($maria, $anuncio->id, $fazendaCompradora, 1000, 'x')['negociacao'];

        $this->assertNull($service->buscar($intruso, $negociacao->id));
    }

    public function test_usuario_de_terceira_fazenda_nao_pode_propor_em_nome_da_compradora(): void
    {
        $fazendaVendedora = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $fazendaCompradora = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $intruso = Usuario::create(['nome' => 'Intruso'])->id;

        $anuncio = Anuncio::create([
            'fazenda_id' => $fazendaVendedora, 'preco_total' => 1000,
            'status' => 'ativo', 'publicado_em' => '2026-01-01',
        ]);
        $animal = Animal::create(['fazenda_id' => $fazendaVendedora, 'lote_id' => null, 'custo_aquisicao' => 1000, 'status' => 'ativo']);
        $anuncio->animais()->attach($animal->id);

        $this->assertThrows(
            fn () => app(NegociacaoService::class)->propor($intruso, $anuncio->id, $fazendaCompradora, 1000, 'x'),
            DomainException::class
        );
    }

    public function test_usuario_de_terceira_fazenda_nao_pode_aceitar_em_nome_da_vendedora(): void
    {
        $fazendaVendedora = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $fazendaCompradora = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $maria = Usuario::create(['nome' => 'Maria'])->id;
        $intruso = Usuario::create(['nome' => 'Intruso'])->id;
        Papel::create(['usuario_id' => $maria, 'fazenda_id' => $fazendaCompradora, 'papel' => 'dono']);

        $anuncio = Anuncio::create([
            'fazenda_id' => $fazendaVendedora, 'preco_total' => 1000,
            'status' => 'ativo', 'publicado_em' => '2026-01-01',
        ]);
        $animal = Animal::create(['fazenda_id' => $fazendaVendedora, 'lote_id' => null, 'custo_aquisicao' => 1000, 'status' => 'ativo']);
        $anuncio->animais()->attach($animal->id);

        $service = app(NegociacaoService::class);
        $negociacao = $service->propor($maria, $anuncio->id, $fazendaCompradora, 1000, 'x')['negociacao'];

        $this->assertThrows(
            fn () => $service->aceitar($intruso, $negociacao->id),
            DomainException::class
        );
    }
}
