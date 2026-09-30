<?php

namespace Tests\Feature\VerticalGta;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Primeira exposição HTTP real do Vertical GTA (módulo Rebanho, wireframe
 * aprovado 16/09/2026). Nenhuma regra de domínio nova aqui: prova só que
 * GtaController é o thin wrapper que SCHEMA-CONTRATO-AUTENTICACAO.md §5
 * já previa, chamando GtaService já provado desde o Vertical 6.
 */
class GtaHttpTest extends TestCase
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

    public function test_registrar_gta_via_http_retorna_201_emitida(): void
    {
        $animal = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 3200, 'status' => 'ativo']);

        $resposta = $this->auth()->postJson('/api/gtas', [
            'fazenda_id' => $this->fazenda,
            'animal_ids' => [$animal->id],
            'destino' => 'Frigorífico São Marcos',
            'valor_bruto' => 9600,
            'data_emissao' => '2026-09-30 10:00:00',
            'chave_idempotencia' => 'gta-http-1',
        ]);

        $resposta->assertStatus(201);
        $resposta->assertJsonPath('reenvio_detectado', false);
        $resposta->assertJsonPath('gta.status', 'emitida');
        $resposta->assertJsonPath('gta.quantidade_declarada', 1);
    }

    public function test_concluir_gta_via_http_gera_venda_real(): void
    {
        $animal = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 3200, 'status' => 'ativo']);
        $gta = $this->auth()->postJson('/api/gtas', [
            'fazenda_id' => $this->fazenda,
            'animal_ids' => [$animal->id],
            'destino' => 'Frigorífico São Marcos',
            'valor_bruto' => 9600,
            'data_emissao' => '2026-09-30 10:00:00',
            'chave_idempotencia' => 'gta-http-2',
        ])->json('gta.id');

        $resposta = $this->auth()->postJson("/api/gtas/{$gta}/concluir");

        $resposta->assertStatus(200);
        $resposta->assertJsonPath('gta.status', 'concluida');
        $this->assertNotNull($resposta->json('gta.venda_id'));
    }

    public function test_listar_gtas_via_http_isola_por_fazenda(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Outra Fazenda'])->id;

        $this->auth()->getJson("/api/gtas?fazenda_id={$outraFazenda}")->assertStatus(422);
    }

    public function test_registrar_gta_sem_token_retorna_401(): void
    {
        $this->postJson('/api/gtas', ['fazenda_id' => $this->fazenda])->assertStatus(401);
    }
}
