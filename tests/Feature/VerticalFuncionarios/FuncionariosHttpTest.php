<?php

namespace Tests\Feature\VerticalFuncionarios;

use App\Models\Fazenda;
use App\Models\Papel;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Primeira exposição HTTP real do módulo Funcionários (Folha de Pagamento
 * + Acerto de Rescisão, wireframe aprovado 16/09/2026). Nenhuma regra de
 * domínio nova: os Controllers são thin wrappers sobre os Services já
 * provados (Verticais 10/32).
 */
class FuncionariosHttpTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private int $fazenda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $usuario = app(AuthService::class)->registrar('José', 'jose@fazenda.com', null, 'senha123');
        Papel::create(['usuario_id' => $usuario->id, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $login = $this->postJson('/api/auth/login', ['identificador' => 'jose@fazenda.com', 'senha' => 'senha123']);
        $this->token = $login->json('token');
    }

    private function auth(): self
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    public function test_contratar_funcionario_via_http_retorna_201(): void
    {
        $resposta = $this->auth()->postJson('/api/funcionarios', [
            'fazenda_id' => $this->fazenda,
            'nome' => 'Eddie',
            'cargo' => 'Peão',
            'salario' => 3000,
            'data_contratacao' => '2026-01-01 08:00:00',
            'chave_idempotencia' => 'func-http-1',
        ]);

        $resposta->assertStatus(201);
        $resposta->assertJsonPath('funcionario.status', 'ativo');
    }

    public function test_gerar_folha_de_pagamento_via_http_cria_forma_pagamento_pendente(): void
    {
        $this->auth()->postJson('/api/funcionarios', [
            'fazenda_id' => $this->fazenda,
            'nome' => 'Eddie',
            'salario' => 3000,
            'data_contratacao' => '2026-01-01 08:00:00',
            'chave_idempotencia' => 'func-http-2',
        ]);

        $resposta = $this->auth()->postJson('/api/folha-pagamento/gerar-mes', [
            'fazenda_id' => $this->fazenda,
            'mes_referencia' => '2026-10',
        ]);

        $resposta->assertStatus(201);
        $this->assertCount(1, $resposta->json('geradas'));

        $formas = $this->auth()->getJson("/api/formas-pagamento?fazenda_id={$this->fazenda}&status=pendente");
        $this->assertCount(1, $formas->json('formas_pagamento'));
    }

    public function test_desligar_e_registrar_acerto_de_rescisao_via_http(): void
    {
        $funcionarioId = $this->auth()->postJson('/api/funcionarios', [
            'fazenda_id' => $this->fazenda,
            'nome' => 'Eddie',
            'salario' => 3000,
            'data_contratacao' => '2026-01-01 08:00:00',
            'chave_idempotencia' => 'func-http-3',
        ])->json('funcionario.id');

        $this->auth()->postJson("/api/funcionarios/{$funcionarioId}/desligar", [
            'data_desligamento' => '2026-10-01 08:00:00',
        ])->assertStatus(200);

        $resposta = $this->auth()->postJson("/api/funcionarios/{$funcionarioId}/acerto-rescisao", [
            'itens' => [
                ['nome' => 'Aviso prévio', 'valor' => 3000, 'vencimento' => '2026-10-10'],
                ['nome' => '13º proporcional', 'valor' => 1500, 'vencimento' => '2026-10-10'],
            ],
            'chave_idempotencia' => 'acerto-http-1',
        ]);

        $resposta->assertStatus(201);
        $this->assertCount(2, $resposta->json('itens'));
    }

    public function test_registrar_acerto_sem_desligar_primeiro_retorna_422(): void
    {
        $funcionarioId = $this->auth()->postJson('/api/funcionarios', [
            'fazenda_id' => $this->fazenda,
            'nome' => 'Eddie',
            'salario' => 3000,
            'data_contratacao' => '2026-01-01 08:00:00',
            'chave_idempotencia' => 'func-http-4',
        ])->json('funcionario.id');

        $resposta = $this->auth()->postJson("/api/funcionarios/{$funcionarioId}/acerto-rescisao", [
            'itens' => [['nome' => 'Aviso prévio', 'valor' => 3000, 'vencimento' => '2026-10-10']],
            'chave_idempotencia' => 'acerto-http-2',
        ]);

        $resposta->assertStatus(422);
    }

    public function test_listar_funcionarios_via_http_isola_por_fazenda(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Outra Fazenda'])->id;

        $this->auth()->getJson("/api/funcionarios?fazenda_id={$outraFazenda}")->assertStatus(422);
    }

    public function test_contratar_sem_token_retorna_401(): void
    {
        $this->postJson('/api/funcionarios', ['fazenda_id' => $this->fazenda])->assertStatus(401);
    }
}
