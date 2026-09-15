<?php

namespace Tests\Feature\VerticalCompra;

use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Papel;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Primeira exposição HTTP real do Vertical Compra (Animal). Nenhuma regra
 * de domínio nova — CompraController é thin wrapper, chama
 * CompraService::registrar() já provado desde o Vertical 2.
 */
class CompraHttpTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private int $fazenda;

    private int $fornecedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->fornecedor = Fornecedor::create(['nome' => 'Fazenda Marília'])->id;
        $usuario = app(AuthService::class)->registrar('José', 'jose@fazenda.com', null, 'senha123');
        Papel::create(['usuario_id' => $usuario->id, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $login = $this->postJson('/api/auth/login', ['identificador' => 'jose@fazenda.com', 'senha' => 'senha123']);
        $this->token = $login->json('token');
    }

    private function auth(): self
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    public function test_registrar_compra_via_http_cria_animais_reais(): void
    {
        $resposta = $this->auth()->postJson('/api/compras', [
            'fazenda_id' => $this->fazenda,
            'fornecedor_id' => $this->fornecedor,
            'valores_por_animal' => [5000, 7500],
            'data_compra' => '2026-09-15',
            'chave_idempotencia' => 'compra-http-1',
        ]);

        $resposta->assertStatus(201);
        $resposta->assertJsonPath('reenvio_detectado', false);
        $resposta->assertJsonPath('compra.valor_total', '12500.00');
    }

    public function test_registrar_compra_com_fornecedor_inexistente_retorna_422(): void
    {
        $resposta = $this->auth()->postJson('/api/compras', [
            'fazenda_id' => $this->fazenda,
            'fornecedor_id' => 999999,
            'valores_por_animal' => [1000],
            'data_compra' => '2026-09-15',
            'chave_idempotencia' => 'compra-http-2',
        ]);

        $resposta->assertStatus(422);
    }

    public function test_registrar_compra_sem_token_retorna_401(): void
    {
        $this->postJson('/api/compras', ['fazenda_id' => $this->fazenda])->assertStatus(401);
    }
}
