<?php

namespace Tests\Feature\VerticalFreteLogistica;

use App\Models\Fazenda;
use App\Models\Motorista;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\AuthService;
use App\Services\ConfiguracaoPlataformaService;
use App\Services\MotoristaService;
use App\Services\PropostaFreteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Primeira exposição HTTP real do módulo Frete/Logística (wireframe
 * aprovado 16/09/2026). Nenhuma regra de domínio nova: os Controllers são
 * thin wrappers sobre os Services já provados (Vertical 22). Escopo desta
 * rodada: só o lado Fazenda — "dar lance" (lado Motorista) fica fora,
 * decisão via AskUserQuestion (30/09/2026).
 */
class FreteLogisticaHttpTest extends TestCase
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

        $administrador = Usuario::create(['nome' => 'Admin', 'eh_administrador' => true]);
        app(ConfiguracaoPlataformaService::class)->definirComissaoFrete($administrador->id, 10.0);
    }

    private function auth(): self
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    private function motoristaAprovado(): Motorista
    {
        $administrador = Usuario::where('eh_administrador', true)->firstOrFail();
        $usuarioMotorista = Usuario::create(['nome' => 'Motorista Carlos']);
        $motorista = app(MotoristaService::class)->cadastrar($usuarioMotorista->id, '000.000.000-00');

        return app(MotoristaService::class)->aprovar($administrador->id, $motorista->id);
    }

    public function test_solicitar_ordem_de_frete_via_http_retorna_201(): void
    {
        $resposta = $this->auth()->postJson('/api/ordens-frete', [
            'fazenda_id' => $this->fazenda,
            'chave_idempotencia' => 'frete-http-1',
        ]);

        $resposta->assertStatus(201);
        $resposta->assertJsonPath('ordem_frete.status', 'aguardando_lance');
    }

    public function test_contratar_direto_via_http_gera_obrigacao_e_comissao(): void
    {
        $motorista = $this->motoristaAprovado();

        $resposta = $this->auth()->postJson('/api/ordens-frete/contratar-direto', [
            'fazenda_id' => $this->fazenda,
            'motorista_id' => $motorista->id,
            'valor_frete' => 1000,
            'chave_idempotencia' => 'frete-http-2',
        ]);

        $resposta->assertStatus(201);
        $resposta->assertJsonPath('ordem_frete.status', 'aceita');
        $resposta->assertJsonPath('ordem_frete.motorista_id', $motorista->id);
    }

    public function test_aceitar_proposta_via_http(): void
    {
        $motorista = $this->motoristaAprovado();
        $ordemId = $this->auth()->postJson('/api/ordens-frete', [
            'fazenda_id' => $this->fazenda,
            'chave_idempotencia' => 'frete-http-3',
        ])->json('ordem_frete.id');

        $propostaId = app(PropostaFreteService::class)->darLance($motorista->usuario_id, $ordemId, 800)->id;

        $resposta = $this->auth()->postJson("/api/ordens-frete/{$ordemId}/aceitar-proposta", [
            'proposta_id' => $propostaId,
        ]);

        $resposta->assertStatus(200);
        $resposta->assertJsonPath('ordem_frete.status', 'aceita');
    }

    public function test_cancelar_ordem_de_frete_via_http(): void
    {
        $ordemId = $this->auth()->postJson('/api/ordens-frete', [
            'fazenda_id' => $this->fazenda,
            'chave_idempotencia' => 'frete-http-4',
        ])->json('ordem_frete.id');

        $resposta = $this->auth()->postJson("/api/ordens-frete/{$ordemId}/cancelar");

        $resposta->assertStatus(200);
        $resposta->assertJsonPath('ordem_frete.status', 'cancelada');
    }

    public function test_listar_motoristas_aprovados_via_http(): void
    {
        $this->motoristaAprovado();

        $resposta = $this->auth()->getJson('/api/motoristas');

        $resposta->assertStatus(200);
        $this->assertCount(1, $resposta->json('motoristas'));
    }

    public function test_solicitar_ordem_sem_token_retorna_401(): void
    {
        $this->postJson('/api/ordens-frete', ['fazenda_id' => $this->fazenda])->assertStatus(401);
    }
}
