<?php

namespace Tests\Feature\VerticalPastagemCurral;

use App\Models\Fazenda;
use App\Models\Lote;
use App\Models\Papel;
use App\Models\Piquete;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Primeira exposição HTTP real do módulo Pastagem/Curral (Rotação de
 * Pastagem + Incêndio, wireframe aprovado 16/09/2026). Nenhuma regra de
 * domínio nova: os Controllers são thin wrappers sobre os Services já
 * provados (Verticais 15/20).
 */
class PastagemCurralHttpTest extends TestCase
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

    public function test_registrar_troca_de_piquete_com_piquete_novo_via_http(): void
    {
        $lote = Lote::create(['fazenda_id' => $this->fazenda, 'qtd_animais' => 5, 'custo_aquisicao' => 10000]);

        $resposta = $this->auth()->postJson('/api/trocas-piquete', [
            'fazenda_id' => $this->fazenda,
            'lote_id' => $lote->id,
            'piquete_novo' => ['nome' => 'Piquete 1', 'dias_descanso' => 30],
            'data_troca' => '2026-09-30 08:00:00',
            'chave_idempotencia' => 'troca-http-1',
        ]);

        $resposta->assertStatus(201);
        $resposta->assertJsonPath('troca.descanso_interrompido', false);
    }

    public function test_listar_piquetes_via_http(): void
    {
        Piquete::create(['fazenda_id' => $this->fazenda, 'nome' => 'Piquete 1', 'dias_descanso' => 30]);

        $resposta = $this->auth()->getJson("/api/piquetes?fazenda_id={$this->fazenda}");

        $resposta->assertStatus(200);
        $this->assertCount(1, $resposta->json('piquetes'));
    }

    public function test_registrar_incendio_via_http(): void
    {
        $piquete = Piquete::create(['fazenda_id' => $this->fazenda, 'nome' => 'Piquete 1', 'dias_descanso' => 30]);

        $resposta = $this->auth()->postJson('/api/incendios', [
            'fazenda_id' => $this->fazenda,
            'piquete_id' => $piquete->id,
            'data_incendio' => '2026-09-30 08:00:00',
            'chave_idempotencia' => 'incendio-http-1',
        ]);

        $resposta->assertStatus(201);
    }

    public function test_listar_trocas_via_http_isola_por_fazenda(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Outra Fazenda'])->id;

        $this->auth()->getJson("/api/trocas-piquete?fazenda_id={$outraFazenda}")->assertStatus(422);
    }

    public function test_registrar_troca_sem_token_retorna_401(): void
    {
        $this->postJson('/api/trocas-piquete', ['fazenda_id' => $this->fazenda])->assertStatus(401);
    }
}
