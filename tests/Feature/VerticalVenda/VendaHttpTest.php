<?php

namespace Tests\Feature\VerticalVenda;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Primeira exposição HTTP real do Vertical Venda (Vertical 33 —
 * Autenticação — habilitou a camada). Nenhuma regra de domínio nova aqui:
 * prova só que VendaController é o thin wrapper que SCHEMA-CONTRATO-
 * AUTENTICACAO.md §5 já previa, chamando VendaService::registrar() já
 * provado desde o Vertical 1.
 */
class VendaHttpTest extends TestCase
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

    public function test_registrar_venda_via_http_retorna_201_com_preview_calculada(): void
    {
        $animal = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 14000, 'status' => 'ativo']);

        $resposta = $this->auth()->postJson('/api/vendas', [
            'fazenda_id' => $this->fazenda,
            'animal_ids' => [$animal->id],
            'valor_bruto' => 20000,
            'data_venda' => '2026-09-15 10:00:00',
            'chave_idempotencia' => 'venda-http-1',
        ]);

        $resposta->assertStatus(201);
        $resposta->assertJsonPath('reenvio_detectado', false);
        $resposta->assertJsonPath('venda.cpv', '14000.00');
        $resposta->assertJsonPath('venda.receita_liquida', '6000.00');
    }

    public function test_listar_vendas_via_http_isola_por_fazenda(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Outra Fazenda'])->id;

        $resposta = $this->auth()->getJson("/api/vendas?fazenda_id={$outraFazenda}");

        $resposta->assertStatus(422);
    }

    public function test_registrar_venda_sem_token_retorna_401(): void
    {
        $this->postJson('/api/vendas', ['fazenda_id' => $this->fazenda])->assertStatus(401);
    }

    public function test_registrar_venda_com_chave_vazia_retorna_422(): void
    {
        $animal = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 1000, 'status' => 'ativo']);

        $resposta = $this->auth()->postJson('/api/vendas', [
            'fazenda_id' => $this->fazenda,
            'animal_ids' => [$animal->id],
            'valor_bruto' => 2000,
            'data_venda' => '2026-09-15 10:00:00',
            'chave_idempotencia' => '',
        ]);

        $resposta->assertStatus(422);
    }
}
