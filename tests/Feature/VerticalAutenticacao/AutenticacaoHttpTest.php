<?php

namespace Tests\Feature\VerticalAutenticacao;

use App\Models\Fazenda;
use App\Models\Papel;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Primeiro teste HTTP real do projeto — os 32 verticais anteriores só
 * exercitavam Services diretamente. SCHEMA-CONTRATO-AUTENTICACAO.md §5:
 * Controllers são thin wrapper, nenhuma lógica de domínio neles — este
 * teste prova a rota real, ponta a ponta (roteamento + middleware +
 * Controller + Service + resposta JSON), não só o Service isolado.
 */
class AutenticacaoHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_registrar_via_http_retorna_201_com_usuario(): void
    {
        $resposta = $this->postJson('/api/auth/registrar', [
            'nome' => 'José', 'email' => 'jose@fazenda.com', 'senha' => 'senha123',
        ]);

        $resposta->assertStatus(201);
        $resposta->assertJsonPath('usuario.nome', 'José');
        $resposta->assertJsonMissingPath('usuario.senha');
    }

    public function test_login_via_http_retorna_token_real(): void
    {
        app(AuthService::class)->registrar('José', 'jose@fazenda.com', null, 'senha123');

        $resposta = $this->postJson('/api/auth/login', [
            'identificador' => 'jose@fazenda.com', 'senha' => 'senha123',
        ]);

        $resposta->assertStatus(200);
        $resposta->assertJsonStructure(['usuario', 'token']);
    }

    /**
     * Achado real ao integrar o frontend: o front precisa saber a Fazenda
     * do usuário logo após o login (pra escopar toda chamada seguinte),
     * sem um 2º round-trip a /api/auth/eu. login() ficava inconsistente
     * com eu() (que já carrega papeis.fazenda) — corrigido pra carregar
     * igual nos dois.
     */
    public function test_login_via_http_ja_retorna_papeis_da_fazenda(): void
    {
        $usuario = app(AuthService::class)->registrar('José', 'jose@fazenda.com', null, 'senha123');
        $fazenda = Fazenda::create(['nome' => 'Fazenda Alegria']);
        Papel::create(['usuario_id' => $usuario->id, 'fazenda_id' => $fazenda->id, 'papel' => 'dono']);

        $resposta = $this->postJson('/api/auth/login', [
            'identificador' => 'jose@fazenda.com', 'senha' => 'senha123',
        ]);

        $resposta->assertStatus(200);
        $resposta->assertJsonPath('usuario.papeis.0.fazenda.nome', 'Fazenda Alegria');
    }

    public function test_login_com_senha_errada_via_http_retorna_422(): void
    {
        app(AuthService::class)->registrar('José', 'jose@fazenda.com', null, 'senha123');

        $resposta = $this->postJson('/api/auth/login', [
            'identificador' => 'jose@fazenda.com', 'senha' => 'errada',
        ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonPath('mensagem', 'Credenciais inválidas.');
    }

    public function test_rota_eu_sem_token_retorna_401(): void
    {
        $this->getJson('/api/auth/eu')->assertStatus(401);
    }

    /** Prova de ponta a ponta: token real do login autentica uma rota protegida real. */
    public function test_rota_eu_com_token_real_retorna_usuario_com_papeis(): void
    {
        $usuario = app(AuthService::class)->registrar('José', 'jose@fazenda.com', null, 'senha123');
        $fazenda = Fazenda::create(['nome' => 'Fazenda Alegria']);
        Papel::create(['usuario_id' => $usuario->id, 'fazenda_id' => $fazenda->id, 'papel' => 'dono']);

        $login = $this->postJson('/api/auth/login', ['identificador' => 'jose@fazenda.com', 'senha' => 'senha123']);
        $token = $login->json('token');

        $resposta = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/auth/eu');

        $resposta->assertStatus(200);
        $resposta->assertJsonPath('usuario.nome', 'José');
        $resposta->assertJsonPath('usuario.papeis.0.fazenda.nome', 'Fazenda Alegria');
    }

    public function test_logout_via_http_revoga_o_token(): void
    {
        app(AuthService::class)->registrar('José', 'jose@fazenda.com', null, 'senha123');
        $login = $this->postJson('/api/auth/login', ['identificador' => 'jose@fazenda.com', 'senha' => 'senha123']);
        $token = $login->json('token');
        $tokenId = explode('|', $token)[0];

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/auth/logout')->assertStatus(200);

        // Achado de harness de teste, não de produto: RequestGuard::user()
        // cacheia o usuário resolvido na própria instância do guard
        // ("não queremos buscar a cada chamada" — comentário do framework).
        // No teste, postJson()/getJson() reaproveitam o mesmo container
        // dentro do mesmo método, então uma 2ª chamada HTTP aqui reusaria o
        // guard já resolvido, nunca reconsultando o banco — falso negativo,
        // nunca reproduz o request real (cada request de produção tem seu
        // próprio container). Verificação real e direta: o token some do
        // banco, mesma prova que AutenticacaoDominioTest já faz no Service.
        $this->assertNull(PersonalAccessToken::find($tokenId));
    }
}
