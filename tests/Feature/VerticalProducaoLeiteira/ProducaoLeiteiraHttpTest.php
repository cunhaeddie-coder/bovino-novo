<?php

namespace Tests\Feature\VerticalProducaoLeiteira;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Primeira exposição HTTP real do Vertical Produção Leiteira (módulo
 * Rebanho, wireframe aprovado 16/09/2026). Nenhuma regra de domínio nova:
 * ProducaoLeiteiraController é thin wrapper sobre ProducaoLeiteiraService,
 * já provado desde o Vertical 14.
 */
class ProducaoLeiteiraHttpTest extends TestCase
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

    public function test_registrar_producao_leiteira_via_http_retorna_201(): void
    {
        $vaca = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 4000, 'status' => 'ativo']);

        $resposta = $this->auth()->postJson('/api/producoes-leiteiras', [
            'fazenda_id' => $this->fazenda,
            'animal_id' => $vaca->id,
            'data_producao' => '2026-09-30 06:00:00',
            'quantidade_total' => 20,
            'quantidade_vendida' => 15,
            'quantidade_bezerro' => 5,
            'chave_idempotencia' => 'leite-http-1',
        ]);

        $resposta->assertStatus(201);
        $resposta->assertJsonPath('reenvio_detectado', false);
    }

    public function test_registrar_producao_leiteira_excedendo_total_retorna_422(): void
    {
        $vaca = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 4000, 'status' => 'ativo']);

        $resposta = $this->auth()->postJson('/api/producoes-leiteiras', [
            'fazenda_id' => $this->fazenda,
            'animal_id' => $vaca->id,
            'data_producao' => '2026-09-30 06:00:00',
            'quantidade_total' => 20,
            'quantidade_vendida' => 15,
            'quantidade_bezerro' => 10,
            'chave_idempotencia' => 'leite-http-2',
        ]);

        $resposta->assertStatus(422);
    }

    public function test_listar_producoes_leiteiras_via_http_isola_por_fazenda(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Outra Fazenda'])->id;

        $this->auth()->getJson("/api/producoes-leiteiras?fazenda_id={$outraFazenda}")->assertStatus(422);
    }

    public function test_registrar_producao_sem_token_retorna_401(): void
    {
        $this->postJson('/api/producoes-leiteiras', ['fazenda_id' => $this->fazenda])->assertStatus(401);
    }
}
