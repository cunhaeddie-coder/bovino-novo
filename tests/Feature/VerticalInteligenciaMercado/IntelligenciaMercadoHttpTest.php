<?php

namespace Tests\Feature\VerticalInteligenciaMercado;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Venda;
use App\Services\AuthService;
use App\Services\IntelligenciaMercadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * Primeira exposição HTTP real do módulo Inteligência de Mercado (Cotações
 * Realizadas). Nenhuma regra nova: IntelligenciaMercadoController é thin
 * wrapper sobre IntelligenciaMercadoService, já provado desde o Vertical 21.
 */
class IntelligenciaMercadoHttpTest extends TestCase
{
    use RefreshDatabase;

    private int $fazenda;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria', 'estado' => 'SP'])->id;
        $usuario = app(AuthService::class)->registrar('José', 'jose@fazenda.com', null, 'senha123');
        Papel::create(['usuario_id' => $usuario->id, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->token = $this->postJson('/api/auth/login', ['identificador' => 'jose@fazenda.com', 'senha' => 'senha123'])->json('token');
    }

    private function auth(): self
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    private function criarVendaConfirmada(string $raca, string $estado, float $valor): void
    {
        $fazenda = Fazenda::create(['nome' => "Vendedora {$raca}-{$estado}-".uniqid(), 'estado' => $estado]);
        $animal = Animal::create(['fazenda_id' => $fazenda->id, 'custo_aquisicao' => 1, 'status' => 'vendido', 'raca' => $raca]);
        $venda = Venda::create([
            'fazenda_id' => $fazenda->id, 'chave_idempotencia' => uniqid('v'), 'animal_ids' => [$animal->id],
            'data_venda' => now(), 'valor_bruto' => $valor, 'cpv' => 0,
            'deducao_fiscal' => 0, 'fiscal_e_premissa' => true, 'receita_liquida' => $valor,
        ]);
        $venda->animais()->attach($animal->id);
        DB::table('vendas')->where('id', $venda->id)->update(['created_at' => now()]);
    }

    public function test_sem_plano_ativo_retorna_422(): void
    {
        $this->auth()->getJson("/api/inteligencia-mercado/cotacoes?fazenda_id={$this->fazenda}")
            ->assertStatus(422);
    }

    public function test_com_plano_ativo_retorna_cotacoes_via_http(): void
    {
        Feature::for(Fazenda::find($this->fazenda))->activate(IntelligenciaMercadoService::FEATURE);

        $this->criarVendaConfirmada('Nelore', 'MT', 3000);
        $this->criarVendaConfirmada('Nelore', 'MT', 4000);
        $this->criarVendaConfirmada('Nelore', 'MT', 5000);

        $resposta = $this->auth()->getJson("/api/inteligencia-mercado/cotacoes?fazenda_id={$this->fazenda}");

        $resposta->assertStatus(200);
        $resposta->assertJsonCount(1, 'cotacoes');
        $resposta->assertJsonPath('cotacoes.0.raca', 'Nelore');
        $resposta->assertJsonPath('cotacoes.0.preco_medio', 4000);
    }

    public function test_sem_token_retorna_401(): void
    {
        $this->getJson("/api/inteligencia-mercado/cotacoes?fazenda_id={$this->fazenda}")->assertStatus(401);
    }
}
