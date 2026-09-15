<?php

namespace Tests\Feature\VerticalFormaPagamento;

use App\Models\Fazenda;
use App\Models\Funcionario;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\AuthService;
use App\Services\FolhaPagamentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Primeira exposição HTTP real do Vertical Forma de Pagamento. Nenhuma
 * regra de domínio nova — FormaPagamentoController é thin wrapper, chama
 * FormaPagamentoService::liquidar() já provado (schema+domínio+concorrência
 * real) desde o vertical original.
 */
class FormaPagamentoHttpTest extends TestCase
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

    /**
     * Achado real ao testar via HTTP: Compra/Venda à vista já nascem pagas
     * (criarFormaPagamentoAVista() grava pago_em na criação, Vertical 27) —
     * não servem pra testar liquidação. Folha de Pagamento é o caso real
     * que nasce PENDENTE (mesmo padrão de FolhaPagamentoDominioTest).
     */
    private function criarFormaPagamentoPendente(): int
    {
        $funcionario = Funcionario::create([
            'fazenda_id' => $this->fazenda, 'nome' => 'Eddie', 'salario' => 3000, 'status' => 'ativo',
            'data_contratacao' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'funcionario-forma-http-1',
        ]);
        $resultado = app(FolhaPagamentoService::class)->gerarMes(
            Usuario::where('email', 'jose@fazenda.com')->firstOrFail()->id,
            $this->fazenda, '2026-09'
        );

        return ObrigacaoFinanceira::where('folha_pagamento_id', $resultado['geradas'][0]->id)
            ->firstOrFail()->formasPagamento()->firstOrFail()->id;
    }

    public function test_listar_formas_pagamento_pendentes_via_http(): void
    {
        $this->criarFormaPagamentoPendente();

        $resposta = $this->auth()->getJson("/api/formas-pagamento?fazenda_id={$this->fazenda}&status=pendente");

        $resposta->assertStatus(200);
        $this->assertCount(1, $resposta->json('formas_pagamento'));
    }

    public function test_liquidar_forma_pagamento_em_dinheiro_via_http(): void
    {
        $formaId = $this->criarFormaPagamentoPendente();

        $resposta = $this->auth()->postJson("/api/formas-pagamento/{$formaId}/liquidar", []);

        $resposta->assertStatus(200);
        $resposta->assertJsonPath('ja_liquidada', false);
        $this->assertNotNull($resposta->json('forma_pagamento.pago_em'));
    }

    public function test_liquidar_ja_liquidada_e_idempotente_via_http(): void
    {
        $formaId = $this->criarFormaPagamentoPendente();
        $this->auth()->postJson("/api/formas-pagamento/{$formaId}/liquidar", []);

        $resposta = $this->auth()->postJson("/api/formas-pagamento/{$formaId}/liquidar", []);

        $resposta->assertStatus(200);
        $resposta->assertJsonPath('ja_liquidada', true);
    }

    public function test_liquidar_sem_token_retorna_401(): void
    {
        $formaId = $this->criarFormaPagamentoPendente();

        $this->postJson("/api/formas-pagamento/{$formaId}/liquidar", [])->assertStatus(401);
    }
}
