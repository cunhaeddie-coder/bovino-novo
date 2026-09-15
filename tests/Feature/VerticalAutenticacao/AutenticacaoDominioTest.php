<?php

namespace Tests\Feature\VerticalAutenticacao;

use App\Services\AuthService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 33 (Autenticação — Gestor/Produtor) — nasce
 * de VERTICAL-AUTENTICACAO.md e SCHEMA-CONTRATO-AUTENTICACAO.md. Primeiro
 * vertical cujo Service não recebe usuarioId (não existe ainda — é
 * exatamente o que este vertical cria).
 */
class AutenticacaoDominioTest extends TestCase
{
    use RefreshDatabase;

    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = app(AuthService::class);
    }

    public function test_registrar_com_email_cria_usuario_com_senha_hasheada(): void
    {
        $usuario = $this->auth->registrar('José', 'jose@fazenda.com', null, 'senha123');

        $this->assertSame('José', $usuario->nome);
        $this->assertNotSame('senha123', $usuario->senha);
        $this->assertTrue(password_verify('senha123', $usuario->senha));
    }

    public function test_registrar_com_celular_tambem_e_valido(): void
    {
        $usuario = $this->auth->registrar('José', null, '69999999999', 'senha123');

        $this->assertSame('69999999999', $usuario->celular);
    }

    public function test_registrar_sem_email_nem_celular_e_recusado(): void
    {
        $this->expectException(DomainException::class);
        $this->auth->registrar('José', null, null, 'senha123');
    }

    public function test_registrar_email_duplicado_e_recusado_com_mensagem_clara(): void
    {
        $this->auth->registrar('José', 'jose@fazenda.com', null, 'senha123');

        $this->expectException(DomainException::class);
        $this->auth->registrar('Outro', 'jose@fazenda.com', null, 'outrasenha');
    }

    public function test_login_com_email_e_senha_correta_emite_token_real(): void
    {
        $this->auth->registrar('José', 'jose@fazenda.com', null, 'senha123');

        $resultado = $this->auth->login('jose@fazenda.com', 'senha123');

        $this->assertSame('José', $resultado['usuario']->nome);
        $this->assertNotEmpty($resultado['token']);
    }

    public function test_login_com_celular_detecta_identificador_sem_arroba(): void
    {
        $this->auth->registrar('José', null, '69999999999', 'senha123');

        $resultado = $this->auth->login('69999999999', 'senha123');

        $this->assertSame('José', $resultado['usuario']->nome);
    }

    public function test_login_com_senha_errada_e_recusado_com_mensagem_generica(): void
    {
        $this->auth->registrar('José', 'jose@fazenda.com', null, 'senha123');

        $this->expectException(DomainException::class);
        $this->auth->login('jose@fazenda.com', 'senha-errada');
    }

    /** Nunca revela se o identificador existe (SCHEMA-CONTRATO-AUTENTICACAO.md §4). */
    public function test_login_com_identificador_inexistente_e_recusado_com_mesma_mensagem(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Credenciais inválidas.');
        $this->auth->login('naoexiste@fazenda.com', 'qualquer');
    }

    public function test_logout_revoga_o_token_atual(): void
    {
        $this->auth->registrar('José', 'jose@fazenda.com', null, 'senha123');
        $resultado = $this->auth->login('jose@fazenda.com', 'senha123');
        $usuario = $resultado['usuario'];

        $tokenId = explode('|', $resultado['token'])[0];
        $this->assertNotNull(PersonalAccessToken::find($tokenId));

        // Simula a requisição autenticada carregando o token atual, mesmo
        // padrão de currentAccessToken() usado pelo Controller real.
        $usuario->withAccessToken(PersonalAccessToken::find($tokenId));
        $this->auth->logout($usuario);

        $this->assertNull(PersonalAccessToken::find($tokenId));
    }
}
