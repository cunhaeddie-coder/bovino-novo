<?php

namespace Tests\Feature\ApiLeituraApoio;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Insumo;
use App\Models\Papel;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Leitura de apoio pras 4 telas da fatia vertical de frontend (Animal,
 * Fornecedor, Insumo) — nunca escreve, isolamento por Fazenda igual a todo
 * Service de domínio (INV-029). Nenhum fato de domínio novo, só exposição
 * HTTP de dados já existentes.
 */
class LeituraApoioHttpTest extends TestCase
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

    public function test_listar_animais_ativos_via_http(): void
    {
        Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 3000, 'status' => 'ativo']);
        Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 3000, 'status' => 'vendido']);

        $resposta = $this->auth()->getJson("/api/animais?fazenda_id={$this->fazenda}&status=ativo");

        $resposta->assertStatus(200);
        $this->assertCount(1, $resposta->json('animais'));
    }

    public function test_listar_animais_de_outra_fazenda_e_recusado(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Outra Fazenda'])->id;

        $this->auth()->getJson("/api/animais?fazenda_id={$outraFazenda}")->assertStatus(422);
    }

    public function test_listar_fornecedores_via_http(): void
    {
        Fornecedor::create(['nome' => 'Fazenda Marília']);

        $resposta = $this->auth()->getJson('/api/fornecedores');

        $resposta->assertStatus(200);
        $this->assertCount(1, $resposta->json('fornecedores'));
    }

    public function test_listar_insumos_via_http(): void
    {
        Insumo::create(['fazenda_id' => $this->fazenda, 'nome' => 'Sal Mineral 25kg', 'quantidade' => 100]);

        $resposta = $this->auth()->getJson("/api/insumos?fazenda_id={$this->fazenda}");

        $resposta->assertStatus(200);
        $this->assertCount(1, $resposta->json('insumos'));
    }

    public function test_leitura_de_apoio_sem_token_retorna_401(): void
    {
        $this->getJson('/api/fornecedores')->assertStatus(401);
    }
}
