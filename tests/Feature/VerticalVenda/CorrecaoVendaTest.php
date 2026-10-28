<?php

namespace Tests\Feature\VerticalVenda;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Lancamento;
use App\Models\LancamentoFiscal;
use App\Models\Papel;
use App\Models\Titular;
use App\Models\Usuario;
use App\Models\Venda;
use App\Services\AuthService;
use App\Services\OutboxService;
use App\Services\VendaService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VERTICAL-VENDA.md §11/§12 — decisões do produtor (28/10/2026, "aprovo as
 * recomendações da Venda"):
 * V1 corrigir é consertar um erro de registro: a venda vale uma vez só,
 *    pela versão atual, na data real; a Carteira recebe só a diferença.
 * V2 correções encadeiam: só a versão atual pode ser corrigida.
 * V3 receita líquida negativa é aceita, sem bloquear.
 * Cenário da página "Correção de Venda": 5 animais de R$600 vendidos por
 * R$5.000 à vista, corrigidos para 4 animais por R$4.000.
 */
class CorrecaoVendaTest extends TestCase
{
    use RefreshDatabase;

    private VendaService $vendas;

    private int $fazenda;

    private int $jose;

    /** @var int[] */
    private array $animais;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendas = app(VendaService::class);
        $titular = Titular::create(['documento' => '11222333000181', 'tipo_documento' => 'cnpj']);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria', 'titular_id' => $titular->id])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->animais = collect(range(1, 5))
            ->map(fn () => Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 600, 'status' => 'ativo'])->id)
            ->all();
    }

    /** V1 — a venda corrigida vale uma vez só: lista, receita e Carteira pela versão atual. */
    public function test_correcao_vale_uma_vez_so_e_a_carteira_recebe_so_a_diferenca(): void
    {
        $original = $this->venderOsCinco();
        app(OutboxService::class)->processarPendentes();
        $correcao = $this->vendas->corrigir($this->jose, $original->id, array_slice($this->animais, 0, 4), 4000.00, 'correcao-1')['correcao'];
        app(OutboxService::class)->processarPendentes();

        $atuais = Venda::atuais()->where('fazenda_id', $this->fazenda)->get();
        $this->assertSame([$correcao->id], $atuais->pluck('id')->all(), 'uma_venda_so_a_versao_atual');
        $this->assertEqualsWithDelta(1600.00, (float) $atuais->sum('receita_liquida'), 0.01, 'receita_4000_menos_custo_2400');

        $this->assertEqualsWithDelta(4000.00, $this->saldoDaCarteira(), 0.01, 'carteira_com_4000_nunca_9000');
        $ajuste = Lancamento::where('tipo', 'saida')->sole();
        $this->assertEqualsWithDelta(1000.00, (float) $ajuste->valor, 0.01);
        $this->assertStringContainsString('corrigida', $ajuste->descricao);
        $this->assertSame('2026-01-10', $ajuste->data_lancamento->format('Y-m-d'), 'ajuste_na_data_da_venda');

        $this->assertSame('ativo', Animal::find($this->animais[4])->status, 'o_quinto_animal_volta_ao_rebanho');
    }

    /** V1 — a versão corrigida mantém a data real da venda, também no lançamento fiscal. */
    public function test_correcao_mantem_a_data_da_venda_tambem_no_fiscal(): void
    {
        $original = $this->venderOsCinco();
        $correcao = $this->vendas->corrigir($this->jose, $original->id, array_slice($this->animais, 0, 4), 4000.00, 'correcao-1')['correcao'];

        $this->assertSame('2026-01-10 10:00:00', $correcao->data_venda->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-10 10:00:00', LancamentoFiscal::where('venda_id', $correcao->id)->sole()->data_lancamento->format('Y-m-d H:i:s'));
    }

    /** V2 — corrigir de novo é sempre sobre a versão atual; a Carteira acompanha cada passo. */
    public function test_correcoes_encadeiam_sobre_a_versao_atual(): void
    {
        $original = $this->venderOsCinco();
        $primeira = $this->vendas->corrigir($this->jose, $original->id, array_slice($this->animais, 0, 4), 4000.00, 'correcao-1')['correcao'];
        $segunda = $this->vendas->corrigir($this->jose, $primeira->id, array_slice($this->animais, 0, 3), 3300.00, 'correcao-2')['correcao'];
        app(OutboxService::class)->processarPendentes();

        $this->assertSame($primeira->id, $segunda->venda_original_id, 'a_segunda_corrige_a_primeira');
        $this->assertSame([$segunda->id], Venda::atuais()->where('fazenda_id', $this->fazenda)->pluck('id')->all());
        $this->assertSame(3, Animal::whereIn('id', $this->animais)->where('status', 'vendido')->count());
        $this->assertEqualsWithDelta(3300.00, $this->saldoDaCarteira(), 0.01);
    }

    /** V2 — a versão já corrigida não aceita outra correção; o reenvio do mesmo pedido continua idempotente. */
    public function test_versao_ja_corrigida_e_recusada_e_reenvio_continua_valendo(): void
    {
        $original = $this->venderOsCinco();
        $primeira = $this->vendas->corrigir($this->jose, $original->id, array_slice($this->animais, 0, 4), 4000.00, 'correcao-1');

        try {
            $this->vendas->corrigir($this->jose, $original->id, [$this->animais[0], $this->animais[1], $this->animais[2], $this->animais[4]], 4000.00, 'correcao-2');
            $this->fail('Corrigir a versão já corrigida era para ser recusado.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('acabou de ser corrigida', $e->getMessage());
        }

        $reenvio = $this->vendas->corrigir($this->jose, $original->id, array_slice($this->animais, 0, 4), 4000.00, 'correcao-1');
        $this->assertTrue($reenvio['reenvio_detectado']);
        $this->assertSame($primeira['correcao']->id, $reenvio['venda']->id);
        $this->assertSame(2, Venda::where('fazenda_id', $this->fazenda)->count());
    }

    /** V2 — última barreira contra duas correções simultâneas da mesma versão. */
    public function test_banco_recusa_duas_correcoes_da_mesma_versao(): void
    {
        $original = $this->venderOsCinco();
        $this->vendas->corrigir($this->jose, $original->id, array_slice($this->animais, 0, 4), 4000.00, 'correcao-1');

        $this->expectException(QueryException::class);
        Venda::create([
            'fazenda_id' => $this->fazenda, 'venda_original_id' => $original->id, 'chave_idempotencia' => 'por-fora',
            'animal_ids' => [$this->animais[0]], 'data_venda' => '2026-01-10 10:00:00', 'valor_bruto' => 1000,
            'cpv' => 600, 'deducao_fiscal' => 0, 'fiscal_e_premissa' => true, 'receita_liquida' => 400,
        ]);
    }

    /** A correção que aumenta o valor entra na Carteira pela diferença, como entrada. */
    public function test_correcao_para_mais_entra_como_ajuste_de_entrada(): void
    {
        $original = $this->venderOsCinco();
        app(OutboxService::class)->processarPendentes();
        $this->vendas->corrigir($this->jose, $original->id, $this->animais, 5600.00, 'correcao-1');
        app(OutboxService::class)->processarPendentes();

        $this->assertEqualsWithDelta(5600.00, $this->saldoDaCarteira(), 0.01);
        $this->assertSame(['entrada', 'entrada'], Lancamento::orderBy('id')->pluck('tipo')->all());
        $this->assertEqualsWithDelta(600.00, (float) Lancamento::orderByDesc('id')->first()->valor, 0.01, 'ajuste_de_600');
    }

    /**
     * Correção feita antes de a varredura processar a venda original (ela
     * roda a cada minuto): a Carteira recebe direto o valor atual, uma
     * entrada só, e reprocessar não muda nada.
     */
    public function test_correcao_antes_da_varredura_ainda_fecha_no_valor_atual(): void
    {
        $original = $this->venderOsCinco();
        $this->vendas->corrigir($this->jose, $original->id, array_slice($this->animais, 0, 4), 4000.00, 'correcao-1');

        app(OutboxService::class)->processarPendentes();
        app(OutboxService::class)->processarPendentes();

        $this->assertEqualsWithDelta(4000.00, $this->saldoDaCarteira(), 0.01);
        $this->assertSame(1, Lancamento::count(), 'uma_entrada_so');
    }

    /** V3 (§11) — venda abaixo do custo é um fato honesto: aceita, com receita líquida negativa. */
    public function test_venda_abaixo_do_custo_e_aceita_com_receita_negativa(): void
    {
        $venda = $this->vendas->registrar($this->jose, $this->fazenda, [$this->animais[0]], 450.00, '2026-01-10 10:00:00', 'descarte')['venda'];

        $this->assertEqualsWithDelta(-150.00, (float) $venda->receita_liquida, 0.01);
    }

    /** A rota de correção responde com a versão nova, e a lista traz só a versão atual. */
    public function test_corrigir_pela_api_e_listar(): void
    {
        $usuario = app(AuthService::class)->registrar('Maria', 'maria@fazenda.com', null, 'senha123');
        Papel::create(['usuario_id' => $usuario->id, 'fazenda_id' => $this->fazenda, 'papel' => 'gerente']);
        $token = $this->postJson('/api/auth/login', ['identificador' => 'maria@fazenda.com', 'senha' => 'senha123'])->json('token');
        $original = $this->venderOsCinco();

        $resposta = $this->withHeader('Authorization', "Bearer {$token}")->postJson("/api/vendas/{$original->id}/corrigir", [
            'novos_animal_ids' => array_slice($this->animais, 0, 4),
            'novo_valor_bruto' => 4000,
            'chave_idempotencia' => 'correcao-http',
        ]);
        $resposta->assertOk();
        $resposta->assertJsonPath('venda.venda_original_id', $original->id);

        $lista = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/vendas?fazenda_id={$this->fazenda}")->assertOk();
        $this->assertSame([$resposta->json('venda.id')], collect($lista->json('vendas'))->pluck('id')->all());

        $this->withHeader('Authorization', "Bearer {$token}")->postJson("/api/vendas/{$original->id}/corrigir", [
            'novos_animal_ids' => array_slice($this->animais, 0, 3),
            'novo_valor_bruto' => 3000,
            'chave_idempotencia' => 'correcao-http-2',
        ])->assertStatus(422)->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'acabou de ser corrigida'));
    }

    private function venderOsCinco(): Venda
    {
        return $this->vendas->registrar($this->jose, $this->fazenda, $this->animais, 5000.00, '2026-01-10 10:00:00', 'venda-original')['venda'];
    }

    private function saldoDaCarteira(): float
    {
        return (float) Lancamento::get()->sum(fn (Lancamento $l) => $l->tipo === 'entrada' ? (float) $l->valor : -(float) $l->valor);
    }
}
