<?php

namespace Tests\Feature\VerticalArrendamento;

use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Papel;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Primeira exposição HTTP real do Vertical Arrendamento (wireframe
 * aprovado 16/09/2026). Nenhuma regra de domínio nova: ArrendamentoController
 * é thin wrapper sobre ArrendamentoService, já provado desde o Vertical 12.
 */
class ArrendamentoHttpTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private int $fazenda;

    private int $fornecedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $usuario = app(AuthService::class)->registrar('José', 'jose@fazenda.com', null, 'senha123');
        Papel::create(['usuario_id' => $usuario->id, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->fornecedor = Fornecedor::create(['nome' => 'Fazenda Vizinha'])->id;
        $login = $this->postJson('/api/auth/login', ['identificador' => 'jose@fazenda.com', 'senha' => 'senha123']);
        $this->token = $login->json('token');
    }

    private function auth(): self
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    public function test_registrar_arrendamento_via_http_retorna_201(): void
    {
        $resposta = $this->auth()->postJson('/api/arrendamentos', [
            'fazenda_id' => $this->fazenda,
            'fornecedor_id' => $this->fornecedor,
            'valor_total' => 12000,
            'periodicidade' => 'mensal',
            'data_inicio' => '2026-10-01 00:00:00',
            'data_fim' => '2027-10-01 00:00:00',
            'chave_idempotencia' => 'arrendamento-http-1',
        ]);

        $resposta->assertStatus(201);
        $resposta->assertJsonPath('reenvio_detectado', false);
    }

    public function test_gerar_proxima_parcela_via_http_cria_forma_pagamento_real(): void
    {
        $arrendamentoId = $this->auth()->postJson('/api/arrendamentos', [
            'fazenda_id' => $this->fazenda,
            'fornecedor_id' => $this->fornecedor,
            'valor_total' => 12000,
            'periodicidade' => 'mensal',
            'data_inicio' => '2026-10-01 00:00:00',
            'data_fim' => '2027-10-01 00:00:00',
            'chave_idempotencia' => 'arrendamento-http-2',
        ])->json('arrendamento.id');

        $resposta = $this->auth()->postJson("/api/arrendamentos/{$arrendamentoId}/gerar-parcela");

        $resposta->assertStatus(201);
        $resposta->assertJsonPath('parcela.numero_parcela', 1);

        $formasPagamento = $this->auth()->getJson("/api/formas-pagamento?fazenda_id={$this->fazenda}&status=pendente");
        $formasPagamento->assertStatus(200);
        $this->assertCount(1, $formasPagamento->json('formas_pagamento'));
    }

    public function test_listar_arrendamentos_via_http_isola_por_fazenda(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Outra Fazenda'])->id;

        $this->auth()->getJson("/api/arrendamentos?fazenda_id={$outraFazenda}")->assertStatus(422);
    }

    public function test_registrar_arrendamento_sem_token_retorna_401(): void
    {
        $this->postJson('/api/arrendamentos', ['fazenda_id' => $this->fazenda])->assertStatus(401);
    }
}
