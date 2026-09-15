<?php

namespace Tests\Feature\VerticalCompraInsumo;

use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Insumo;
use App\Models\Papel;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Primeira exposição HTTP real do Vertical Compra de Insumo. Nenhuma regra
 * de domínio nova — CompraInsumoController é thin wrapper, chama
 * CompraInsumoService::registrar() já provado desde o Vertical 3.
 */
class CompraInsumoHttpTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private int $fazenda;

    private int $fornecedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->fornecedor = Fornecedor::create(['nome' => 'Agropecuária Central'])->id;
        $usuario = app(AuthService::class)->registrar('José', 'jose@fazenda.com', null, 'senha123');
        Papel::create(['usuario_id' => $usuario->id, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $login = $this->postJson('/api/auth/login', ['identificador' => 'jose@fazenda.com', 'senha' => 'senha123']);
        $this->token = $login->json('token');
    }

    private function auth(): self
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    public function test_registrar_compra_insumo_com_insumo_existente_e_novo_via_http(): void
    {
        $insumo = Insumo::create(['fazenda_id' => $this->fazenda, 'nome' => 'Sal Mineral 25kg', 'quantidade' => 100]);

        $resposta = $this->auth()->postJson('/api/compras-insumo', [
            'fazenda_id' => $this->fazenda,
            'fornecedor_id' => $this->fornecedor,
            'itens' => [
                ['insumo_id' => $insumo->id, 'quantidade' => 50, 'valor_unitario' => 17],
                ['insumo_novo' => ['nome' => 'Fosbovi Advance 25kg'], 'quantidade' => 80, 'valor_unitario' => 12],
            ],
            'data_compra' => '2026-09-15',
            'chave_idempotencia' => 'compra-insumo-http-1',
        ]);

        $resposta->assertStatus(201);
        $resposta->assertJsonPath('reenvio_detectado', false);
    }

    public function test_insumo_de_outra_fazenda_e_recusado(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Outra Fazenda'])->id;
        $insumoAlheio = Insumo::create(['fazenda_id' => $outraFazenda, 'nome' => 'Sal Mineral', 'quantidade' => 10]);

        $resposta = $this->auth()->postJson('/api/compras-insumo', [
            'fazenda_id' => $this->fazenda,
            'fornecedor_id' => $this->fornecedor,
            'itens' => [
                ['insumo_id' => $insumoAlheio->id, 'quantidade' => 5, 'valor_unitario' => 10],
            ],
            'data_compra' => '2026-09-15',
            'chave_idempotencia' => 'compra-insumo-http-2',
        ]);

        $resposta->assertStatus(422);
    }

    public function test_registrar_compra_insumo_sem_token_retorna_401(): void
    {
        $this->postJson('/api/compras-insumo', ['fazenda_id' => $this->fazenda])->assertStatus(401);
    }
}
