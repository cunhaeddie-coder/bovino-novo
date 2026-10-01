<?php

namespace Tests\Feature\VerticalMarketplace;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Kyc;
use App\Models\Papel;
use App\Models\Titular;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Primeira exposição HTTP real do módulo Marketplace (Anúncio +
 * Negociação, ciclo integrado já fechado no domínio). Nenhuma regra nova:
 * AnuncioController/NegociacaoController são thin wrappers sobre os
 * Services já provados.
 */
class MarketplaceHttpTest extends TestCase
{
    use RefreshDatabase;

    private int $fazendaVendedora;

    private int $fazendaCompradora;

    private string $tokenVendedor;

    private string $tokenComprador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fazendaVendedora = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->fazendaCompradora = Fazenda::create(['nome' => 'Sítio Alegria'])->id;

        $joao = app(AuthService::class)->registrar('João', 'joao@fazenda.com', null, 'senha123');
        $maria = app(AuthService::class)->registrar('Maria', 'maria@sitio.com', null, 'senha123');
        Papel::create(['usuario_id' => $joao->id, 'fazenda_id' => $this->fazendaVendedora, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $maria->id, 'fazenda_id' => $this->fazendaCompradora, 'papel' => 'dono']);

        $this->aprovarKyc($this->fazendaVendedora);
        $this->aprovarKyc($this->fazendaCompradora);

        $this->tokenVendedor = $this->postJson('/api/auth/login', ['identificador' => 'joao@fazenda.com', 'senha' => 'senha123'])->json('token');
        $this->tokenComprador = $this->postJson('/api/auth/login', ['identificador' => 'maria@sitio.com', 'senha' => 'senha123'])->json('token');
    }

    private function aprovarKyc(int $fazendaId): void
    {
        $titular = Titular::create(['documento' => '111.444.777-35'.uniqid(), 'tipo_documento' => 'cpf']);
        Fazenda::where('id', $fazendaId)->update(['titular_id' => $titular->id]);
        Kyc::create(['titular_id' => $titular->id, 'status' => 'aprovado', 'verificado_em' => now()]);
    }

    /**
     * Guard sanctum é RequestGuard: cacheia o usuário resolvido na 1ª
     * chamada e ignora o header em chamadas seguintes dentro do mesmo
     * teste — Auth::forgetGuards() força resolver de novo a partir do
     * Bearer token atual. Só é necessário aqui porque este é o 1º módulo
     * com 2 atores autenticados reais no mesmo teste.
     */
    private function como(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function criarAnuncio(): int
    {
        $animal = Animal::create(['fazenda_id' => $this->fazendaVendedora, 'lote_id' => null, 'custo_aquisicao' => 1000, 'status' => 'ativo']);

        $resposta = $this->como($this->tokenVendedor)->postJson('/api/anuncios', [
            'fazenda_id' => $this->fazendaVendedora,
            'preco_total' => 1200,
            'animal_ids' => [$animal->id],
        ]);
        $resposta->assertStatus(201);

        return $resposta->json('anuncio.id');
    }

    public function test_publicar_anuncio_via_http_retorna_201(): void
    {
        $anuncioId = $this->criarAnuncio();
        $this->assertIsInt($anuncioId);
    }

    public function test_listar_mercado_via_http_mostra_anuncios_ativos_de_outra_fazenda(): void
    {
        $this->criarAnuncio();

        $resposta = $this->como($this->tokenComprador)->getJson('/api/anuncios');
        $resposta->assertStatus(200);
        $this->assertCount(1, $resposta->json('anuncios'));
    }

    public function test_ciclo_completo_propor_aceitar_confirmar_via_http(): void
    {
        $anuncioId = $this->criarAnuncio();

        $negociacaoId = $this->como($this->tokenComprador)->postJson('/api/negociacoes', [
            'anuncio_id' => $anuncioId,
            'fazenda_compradora_id' => $this->fazendaCompradora,
            'preco_proposto' => 1200,
            'chave_idempotencia' => 'nego-http-1',
        ])->json('negociacao.id');

        $this->como($this->tokenVendedor)
            ->postJson("/api/negociacoes/{$negociacaoId}/aceitar")
            ->assertStatus(200);

        $this->como($this->tokenVendedor)
            ->postJson("/api/negociacoes/{$negociacaoId}/confirmar-vendedor")
            ->assertStatus(200);

        $resposta = $this->como($this->tokenComprador)
            ->postJson("/api/negociacoes/{$negociacaoId}/confirmar-comprador");

        $resposta->assertStatus(200);
        $resposta->assertJsonPath('negociacao.status', 'concluida');

        $vendas = $this->como($this->tokenVendedor)
            ->getJson("/api/vendas?fazenda_id={$this->fazendaVendedora}");
        $this->assertCount(1, $vendas->json('vendas'));

        $compras = $this->como($this->tokenComprador)
            ->getJson("/api/compras?fazenda_id={$this->fazendaCompradora}");
        $this->assertCount(1, $compras->json('compras'));
    }

    public function test_listar_negociacoes_via_http_visivel_pelos_2_lados(): void
    {
        $anuncioId = $this->criarAnuncio();
        $this->como($this->tokenComprador)->postJson('/api/negociacoes', [
            'anuncio_id' => $anuncioId,
            'fazenda_compradora_id' => $this->fazendaCompradora,
            'preco_proposto' => 1200,
            'chave_idempotencia' => 'nego-http-2',
        ]);

        $vendedor = $this->como($this->tokenVendedor)
            ->getJson("/api/negociacoes?fazenda_id={$this->fazendaVendedora}");
        $comprador = $this->como($this->tokenComprador)
            ->getJson("/api/negociacoes?fazenda_id={$this->fazendaCompradora}");

        $this->assertCount(1, $vendedor->json('negociacoes'));
        $this->assertCount(1, $comprador->json('negociacoes'));
    }

    public function test_propor_sem_kyc_aprovado_retorna_422(): void
    {
        $anuncioId = $this->criarAnuncio();

        $fazendaSemKyc = Fazenda::create(['nome' => 'Fazenda Sem KYC'])->id;
        $pedro = app(AuthService::class)->registrar('Pedro', 'pedro@semkyc.com', null, 'senha123');
        Papel::create(['usuario_id' => $pedro->id, 'fazenda_id' => $fazendaSemKyc, 'papel' => 'dono']);
        $token = $this->postJson('/api/auth/login', ['identificador' => 'pedro@semkyc.com', 'senha' => 'senha123'])->json('token');

        $resposta = $this->como($token)->postJson('/api/negociacoes', [
            'anuncio_id' => $anuncioId,
            'fazenda_compradora_id' => $fazendaSemKyc,
            'preco_proposto' => 1200,
            'chave_idempotencia' => 'nego-http-3',
        ]);

        $resposta->assertStatus(422);
    }

    public function test_publicar_anuncio_sem_token_retorna_401(): void
    {
        $this->postJson('/api/anuncios', ['fazenda_id' => $this->fazendaVendedora])->assertStatus(401);
    }
}
