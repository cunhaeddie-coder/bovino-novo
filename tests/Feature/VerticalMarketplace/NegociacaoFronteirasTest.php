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

class NegociacaoFronteirasTest extends TestCase
{
    use RefreshDatabase;

    private int $fazendaVendedora;

    private int $fazendaCompradora;

    private int $joao;

    private int $maria;

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
        $animal = Animal::create(['fazenda_id' => $this->fazendaVendedora, 'lote_id' => null, 'custo_aquisicao' => 1000, 'status' => 'ativo']);
        $this->anuncio->animais()->attach($animal->id);
    }

    public function test_chave_idempotencia_vazia_e_recusada(): void
    {
        $this->assertThrows(
            fn () => app(NegociacaoService::class)->propor($this->maria, $this->anuncio->id, $this->fazendaCompradora, 1000, '   '),
            DomainException::class
        );
    }

    public function test_preco_proposto_zero_ou_negativo_e_recusado(): void
    {
        $this->assertThrows(
            fn () => app(NegociacaoService::class)->propor($this->maria, $this->anuncio->id, $this->fazendaCompradora, 0, 'x'),
            DomainException::class
        );
    }

    public function test_propor_sobre_anuncio_inativo_e_recusado(): void
    {
        $this->anuncio->update(['status' => 'vendido', 'encerrado_em' => '2026-01-02']);

        $this->assertThrows(
            fn () => app(NegociacaoService::class)->propor($this->maria, $this->anuncio->id, $this->fazendaCompradora, 1000, 'x'),
            DomainException::class
        );
    }

    public function test_usuario_sem_relacao_com_fazenda_compradora_nao_pode_propor(): void
    {
        $intruso = Usuario::create(['nome' => 'Intruso'])->id;

        $this->assertThrows(
            fn () => app(NegociacaoService::class)->propor($intruso, $this->anuncio->id, $this->fazendaCompradora, 1000, 'x'),
            DomainException::class
        );
    }

    public function test_aceitar_so_pode_ser_feito_pela_fazenda_vendedora(): void
    {
        $service = app(NegociacaoService::class);
        $negociacao = $service->propor($this->maria, $this->anuncio->id, $this->fazendaCompradora, 1000, 'x')['negociacao'];

        $this->assertThrows(
            fn () => $service->aceitar($this->maria, $negociacao->id),
            DomainException::class
        );
    }

    public function test_confirmar_sem_estar_aceita_e_recusado(): void
    {
        $service = app(NegociacaoService::class);
        $negociacao = $service->propor($this->maria, $this->anuncio->id, $this->fazendaCompradora, 1000, 'x')['negociacao'];

        $this->assertThrows(
            fn () => $service->confirmarVendedor($this->joao, $negociacao->id),
            DomainException::class
        );
        $this->assertThrows(
            fn () => $service->confirmarComprador($this->maria, $negociacao->id),
            DomainException::class
        );
    }

    public function test_confirmar_vendedor_com_usuario_da_fazenda_compradora_e_recusado(): void
    {
        $service = app(NegociacaoService::class);
        $negociacao = $service->propor($this->maria, $this->anuncio->id, $this->fazendaCompradora, 1000, 'x')['negociacao'];
        $service->aceitar($this->joao, $negociacao->id);

        $this->assertThrows(
            fn () => $service->confirmarVendedor($this->maria, $negociacao->id),
            DomainException::class
        );
    }
}
