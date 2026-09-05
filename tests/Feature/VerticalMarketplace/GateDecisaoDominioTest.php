<?php

namespace Tests\Feature\VerticalMarketplace;

use App\Models\Animal;
use App\Models\Anuncio;
use App\Models\Fazenda;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\NegociacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VERTICAL-MARKETPLACE.md §8 / SCHEMA-CONTRATO-MARKETPLACE.md — trava as
 * decisões centrais do produtor como regressão permanente: a ponte é
 * literal (INV-002/INV-003, "canal não define o fato"), e INV-023 (Anúncio
 * nunca fica órfão de item já vendido) — mesmo padrão de GateDecisaoDominioTest
 * dos verticais anteriores.
 */
class GateDecisaoDominioTest extends TestCase
{
    use RefreshDatabase;

    public function test_ponte_e_literal_venda_e_compra_reais_nascem_com_obrigacao_e_forma_de_pagamento(): void
    {
        $fazendaVendedora = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $fazendaCompradora = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $joao = Usuario::create(['nome' => 'João'])->id;
        $maria = Usuario::create(['nome' => 'Maria'])->id;
        Papel::create(['usuario_id' => $joao, 'fazenda_id' => $fazendaVendedora, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $maria, 'fazenda_id' => $fazendaCompradora, 'papel' => 'dono']);

        $anuncio = Anuncio::create([
            'fazenda_id' => $fazendaVendedora, 'preco_total' => 800,
            'status' => 'ativo', 'publicado_em' => '2026-01-01',
        ]);
        $animalOriginal = Animal::create(['fazenda_id' => $fazendaVendedora, 'lote_id' => null, 'custo_aquisicao' => 400, 'status' => 'ativo']);
        $anuncio->animais()->attach($animalOriginal->id);

        $service = app(NegociacaoService::class);
        $negociacao = $service->propor($maria, $anuncio->id, $fazendaCompradora, 800, 'x')['negociacao'];
        $service->aceitar($joao, $negociacao->id);
        $resultadoVendedor = $service->confirmarVendedor($joao, $negociacao->id);
        $resultadoComprador = $service->confirmarComprador($maria, $negociacao->id);

        $venda = $resultadoVendedor['venda']->fresh();
        $compra = $resultadoComprador['compra']->fresh();

        // INV-003 — canal não define o fato: a Venda nasceu com o mesmo
        // núcleo obrigatório de qualquer outra Venda (ObrigacaoFinanceira +
        // FormaPagamento "à vista"), nunca uma segunda contabilidade.
        $obrigacaoVenda = ObrigacaoFinanceira::where('venda_id', $venda->id)->first();
        $this->assertNotNull($obrigacaoVenda, 'venda_do_marketplace_gera_obrigacao_financeira_real');
        $this->assertSame('a_receber', $obrigacaoVenda->direcao);
        $this->assertSame(1, $obrigacaoVenda->formasPagamento()->count());

        $obrigacaoCompra = ObrigacaoFinanceira::where('compra_id', $compra->id)->first();
        $this->assertNotNull($obrigacaoCompra, 'compra_do_marketplace_gera_obrigacao_financeira_real');
        $this->assertSame('a_pagar', $obrigacaoCompra->direcao);

        // O animal original fica marcado vendido na Fazenda vendedora — a
        // Compra cria um Animal NOVO na Fazenda compradora (mesmo mecanismo
        // que CompraService já usa pra qualquer Compra normal), nunca
        // transfere o mesmo registro.
        $this->assertSame('vendido', $animalOriginal->fresh()->status);
        $animalNovo = Animal::where('fazenda_id', $fazendaCompradora)->first();
        $this->assertNotNull($animalNovo);
        $this->assertNotSame($animalOriginal->id, $animalNovo->id);
    }

    public function test_anuncio_nunca_fica_ativo_depois_de_vendido_inv023(): void
    {
        $fazendaVendedora = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $fazendaCompradora = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $joao = Usuario::create(['nome' => 'João'])->id;
        $maria = Usuario::create(['nome' => 'Maria'])->id;
        Papel::create(['usuario_id' => $joao, 'fazenda_id' => $fazendaVendedora, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $maria, 'fazenda_id' => $fazendaCompradora, 'papel' => 'dono']);

        $anuncio = Anuncio::create([
            'fazenda_id' => $fazendaVendedora, 'preco_total' => 500,
            'status' => 'ativo', 'publicado_em' => '2026-01-01',
        ]);
        $animal = Animal::create(['fazenda_id' => $fazendaVendedora, 'lote_id' => null, 'custo_aquisicao' => 500, 'status' => 'ativo']);
        $anuncio->animais()->attach($animal->id);

        $service = app(NegociacaoService::class);
        $negociacao = $service->propor($maria, $anuncio->id, $fazendaCompradora, 500, 'x')['negociacao'];
        $service->aceitar($joao, $negociacao->id);

        $this->assertSame('ativo', $anuncio->fresh()->status, 'ainda_ativo_antes_da_confirmacao_do_vendedor');

        $service->confirmarVendedor($joao, $negociacao->id);

        $this->assertSame('vendido', $anuncio->fresh()->status);
        $this->assertNotNull($anuncio->fresh()->encerrado_em);
    }
}
