<?php

namespace Tests\Feature\VerticalEventoSaude;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Insumo;
use App\Models\Papel;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Primeira exposição HTTP real do Vertical Evento de Saúde (módulo
 * Rebanho/Saúde, wireframe aprovado 16/09/2026). Nenhuma regra de domínio
 * nova: EventoSaudeController é thin wrapper sobre EventoSaudeService, já
 * provado desde o Vertical 11 (+ extensões 18/31).
 */
class EventoSaudeHttpTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private int $fazenda;

    private int $insumo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $usuario = app(AuthService::class)->registrar('José', 'jose@fazenda.com', null, 'senha123');
        Papel::create(['usuario_id' => $usuario->id, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->insumo = Insumo::create(['fazenda_id' => $this->fazenda, 'nome' => 'Vacina Aftosa', 'quantidade' => 100])->id;
        $login = $this->postJson('/api/auth/login', ['identificador' => 'jose@fazenda.com', 'senha' => 'senha123']);
        $this->token = $login->json('token');
    }

    private function auth(): self
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    public function test_registrar_evento_de_saude_via_http_retorna_201(): void
    {
        $animal = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 3200, 'status' => 'ativo']);

        $resposta = $this->auth()->postJson('/api/eventos-saude', [
            'fazenda_id' => $this->fazenda,
            'animal_ids' => [$animal->id],
            'insumo_id' => $this->insumo,
            'quantidade' => 2,
            'descricao' => 'Vacinação de rotina',
            'data_aplicacao' => '2026-09-30 10:00:00',
            'chave_idempotencia' => 'saude-http-1',
        ]);

        $resposta->assertStatus(201);
        $resposta->assertJsonPath('reenvio_detectado', false);
        $resposta->assertJsonPath('evento.descricao', 'Vacinação de rotina');
    }

    public function test_registrar_vacina_obrigatoria_com_certificado_via_http(): void
    {
        $animal = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 3200, 'status' => 'ativo']);

        $resposta = $this->auth()->postJson('/api/eventos-saude', [
            'fazenda_id' => $this->fazenda,
            'animal_ids' => [$animal->id],
            'insumo_id' => $this->insumo,
            'quantidade' => 2,
            'descricao' => 'Vacinação obrigatória',
            'data_aplicacao' => '2026-09-30 10:00:00',
            'chave_idempotencia' => 'saude-http-2',
            'certificado' => 'CERT-001',
            'tipo_vacina' => 'Aftosa',
        ]);

        $resposta->assertStatus(201);
        $resposta->assertJsonPath('evento.certificado', 'CERT-001');
        $resposta->assertJsonPath('evento.tipo_vacina', 'Aftosa');
    }

    public function test_registrar_evento_so_com_certificado_sem_tipo_vacina_retorna_422(): void
    {
        $animal = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 3200, 'status' => 'ativo']);

        $resposta = $this->auth()->postJson('/api/eventos-saude', [
            'fazenda_id' => $this->fazenda,
            'animal_ids' => [$animal->id],
            'insumo_id' => $this->insumo,
            'quantidade' => 2,
            'descricao' => 'Vacinação',
            'data_aplicacao' => '2026-09-30 10:00:00',
            'chave_idempotencia' => 'saude-http-3',
            'certificado' => 'CERT-001',
        ]);

        $resposta->assertStatus(422);
    }

    public function test_listar_eventos_de_saude_via_http_isola_por_fazenda(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Outra Fazenda'])->id;

        $this->auth()->getJson("/api/eventos-saude?fazenda_id={$outraFazenda}")->assertStatus(422);
    }

    public function test_registrar_evento_sem_token_retorna_401(): void
    {
        $this->postJson('/api/eventos-saude', ['fazenda_id' => $this->fazenda])->assertStatus(401);
    }
}
