<?php

namespace Tests\Feature\VerticalAutenticacao;

use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Limite de tentativas no login (Inversão, 26/10/2026): 5 por minuto por
 * identificador + IP, resposta 429 no formato {mensagem}. Sem isso, a
 * homologação na internet aceitaria tentativa de senha sem limite.
 */
class LimiteTentativasLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(AuthService::class)->registrar('José', 'jose@fazenda.com', null, 'senha123');
    }

    private function tentar(string $identificador, string $senha = 'errada')
    {
        return $this->postJson('/api/auth/login', ['identificador' => $identificador, 'senha' => $senha]);
    }

    public function test_sexta_tentativa_no_mesmo_minuto_recebe_429_com_mensagem(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->tentar('jose@fazenda.com')->assertStatus(422);
        }

        $resposta = $this->tentar('jose@fazenda.com');

        $resposta->assertStatus(429);
        $resposta->assertHeader('Retry-After');
        $this->assertStringStartsWith('Muitas tentativas de login.', $resposta->json('mensagem'));
    }

    public function test_bloqueio_vale_ate_para_a_senha_certa(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->tentar('jose@fazenda.com');
        }

        $this->tentar('jose@fazenda.com', 'senha123')->assertStatus(429);
    }

    public function test_variacao_de_maiuscula_e_espaco_conta_como_o_mesmo_identificador(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->tentar($i % 2 === 0 ? 'JOSE@fazenda.com' : ' jose@fazenda.com ');
        }

        $this->tentar('jose@fazenda.com')->assertStatus(429);
    }

    public function test_outro_identificador_do_mesmo_ip_nao_e_bloqueado(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->tentar('jose@fazenda.com');
        }

        $this->tentar('maria@fazenda.com')->assertStatus(422);
    }

    /** SCHEMA-CONTRATO-AUTENTICACAO.md §4: o 429 não pode revelar se o cadastro existe. */
    public function test_identificador_inexistente_recebe_o_mesmo_bloqueio(): void
    {
        // Relógio parado só pra o Retry-After (e a mensagem) sair igual nos dois.
        $this->freezeTime();
        foreach (['ninguem@fazenda.com', 'jose@fazenda.com'] as $identificador) {
            for ($i = 0; $i < 5; $i++) {
                $this->tentar($identificador)->assertStatus(422);
            }
        }

        $inexistente = $this->tentar('ninguem@fazenda.com');
        $existente = $this->tentar('jose@fazenda.com');

        $inexistente->assertStatus(429);
        $existente->assertStatus(429);
        $this->assertSame($existente->json('mensagem'), $inexistente->json('mensagem'));
    }

    public function test_depois_de_um_minuto_o_login_volta_a_funcionar(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->tentar('jose@fazenda.com');
        }
        $this->tentar('jose@fazenda.com', 'senha123')->assertStatus(429);

        $this->travel(61)->seconds();

        $this->tentar('jose@fazenda.com', 'senha123')->assertStatus(200);
    }
}
