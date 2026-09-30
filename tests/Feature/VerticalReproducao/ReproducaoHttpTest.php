<?php

namespace Tests\Feature\VerticalReproducao;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Primeira exposição HTTP real do módulo Reprodução (Protocolo
 * Reprodutivo/IATF + Marcação de Cio + Confirmação de Prenhez, wireframe
 * aprovado 16/09/2026). Nenhuma regra de domínio nova: os 3 Controllers
 * são thin wrappers sobre os Services já provados (Verticais 13/17).
 */
class ReproducaoHttpTest extends TestCase
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

    public function test_iniciar_protocolo_reprodutivo_via_http_cria_3_etapas(): void
    {
        $animal = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 4000, 'status' => 'ativo']);

        $resposta = $this->auth()->postJson('/api/protocolos-reprodutivos', [
            'fazenda_id' => $this->fazenda,
            'animal_ids' => [$animal->id],
            'data_inicio' => '2026-09-30 08:00:00',
            'chave_idempotencia' => 'protocolo-http-1',
        ]);

        $resposta->assertStatus(201);
        $resposta->assertJsonPath('protocolo.status', 'em_andamento');
        $this->assertCount(3, $resposta->json('protocolo.etapas'));
    }

    public function test_cumprir_etapa_via_http_conclui_protocolo_na_terceira(): void
    {
        $animal = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 4000, 'status' => 'ativo']);
        $protocoloId = $this->auth()->postJson('/api/protocolos-reprodutivos', [
            'fazenda_id' => $this->fazenda,
            'animal_ids' => [$animal->id],
            'data_inicio' => '2026-09-30 08:00:00',
            'chave_idempotencia' => 'protocolo-http-2',
        ])->json('protocolo.id');

        $this->auth()->postJson("/api/protocolos-reprodutivos/{$protocoloId}/etapas", ['tipo_etapa' => 'prostaglandina', 'data_realizada' => '2026-10-07 08:00:00'])->assertStatus(200);
        $resposta = $this->auth()->postJson("/api/protocolos-reprodutivos/{$protocoloId}/etapas", ['tipo_etapa' => 'retirada_ia', 'data_realizada' => '2026-10-09 08:00:00']);

        $resposta->assertStatus(200);
        $resposta->assertJsonPath('protocolo.status', 'concluido');
    }

    public function test_registrar_marcacao_de_cio_via_http(): void
    {
        $vaca = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 4000, 'status' => 'ativo']);
        $rufiao = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 3000, 'status' => 'ativo']);

        $resposta = $this->auth()->postJson('/api/marcacoes-cio', [
            'fazenda_id' => $this->fazenda,
            'vaca_id' => $vaca->id,
            'rufiao_id' => $rufiao->id,
            'data_marcacao' => '2026-09-30 08:00:00',
            'chave_idempotencia' => 'marcacao-http-1',
        ]);

        $resposta->assertStatus(201);
        $resposta->assertJsonPath('reenvio_detectado', false);
    }

    public function test_registrar_confirmacao_prenhez_positiva_calcula_data_parto_via_http(): void
    {
        $vaca = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 4000, 'status' => 'ativo']);

        $resposta = $this->auth()->postJson('/api/confirmacoes-prenhez', [
            'fazenda_id' => $this->fazenda,
            'vaca_id' => $vaca->id,
            'resultado' => 'positivo',
            'tipo_exame' => 'ultrassom',
            'data_confirmacao' => '2026-09-30 08:00:00',
            'chave_idempotencia' => 'prenhez-http-1',
        ]);

        $resposta->assertStatus(201);
        $this->assertNotNull($resposta->json('confirmacao.data_parto_estimada'));
    }

    public function test_registrar_confirmacao_prenhez_negativa_sem_token_retorna_401(): void
    {
        $this->postJson('/api/confirmacoes-prenhez', ['fazenda_id' => $this->fazenda])->assertStatus(401);
    }
}
