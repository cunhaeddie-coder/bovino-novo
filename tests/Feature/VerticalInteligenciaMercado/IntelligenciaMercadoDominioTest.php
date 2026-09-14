<?php

namespace Tests\Feature\VerticalInteligenciaMercado;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Models\Venda;
use App\Services\IntelligenciaMercadoService;
use App\Services\VendaService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 21 (Inteligência de Mercado — Cotações
 * Realizadas) — nasce de VERTICAL-INTELIGENCIA-MERCADO.md e
 * SCHEMA-CONTRATO-INTELIGENCIA-MERCADO.md. Reproduz LAB-SA-023 (consulta de
 * preço médio por raça/estado), já com a correção de política de 24/08/2026
 * (gateado por plano, nunca aberto).
 */
class IntelligenciaMercadoDominioTest extends TestCase
{
    use RefreshDatabase;

    private IntelligenciaMercadoService $inteligencia;

    private int $fazendaConsultante;

    private int $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inteligencia = app(IntelligenciaMercadoService::class);
        $this->fazendaConsultante = Fazenda::create(['nome' => 'Quem consulta', 'estado' => 'SP'])->id;
        $this->usuario = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->usuario, 'fazenda_id' => $this->fazendaConsultante, 'papel' => 'dono']);
    }

    /** Cria uma Venda de 1 animal já vendida, com raça/estado e data controladas — sem passar por VendaService (foco na agregação, não no fluxo de venda). */
    private function criarVendaConfirmada(string $raca, string $estado, float $valor, string $criadaEm): Venda
    {
        $fazenda = Fazenda::create(['nome' => "Vendedora {$raca}-{$estado}-".uniqid(), 'estado' => $estado]);
        $animal = Animal::create(['fazenda_id' => $fazenda->id, 'custo_aquisicao' => 1, 'status' => 'vendido', 'raca' => $raca]);
        $venda = Venda::create([
            'fazenda_id' => $fazenda->id, 'chave_idempotencia' => uniqid('v'), 'animal_ids' => [$animal->id],
            'data_venda' => $criadaEm, 'valor_bruto' => $valor, 'cpv' => 0,
            'deducao_fiscal' => 0, 'fiscal_e_premissa' => true, 'receita_liquida' => $valor,
        ]);
        $venda->animais()->attach($animal->id);

        // Venda é imutável via Eloquent (VendaBuilder::update() bloqueia,
        // INV-026) — DB::table() é o escape hatch já documentado ali pra
        // casos fora do domínio (aqui, só ajustar o relógio do teste).
        DB::table('vendas')->where('id', $venda->id)->update(['created_at' => $criadaEm]);

        return $venda->fresh();
    }

    public function test_sem_plano_ativo_consulta_e_recusada(): void
    {
        $this->expectException(DomainException::class);
        $this->inteligencia->cotacoesRealizadas($this->usuario, $this->fazendaConsultante);
    }

    public function test_usuario_sem_relacao_com_fazenda_e_recusado(): void
    {
        Feature::for(Fazenda::find($this->fazendaConsultante))->activate(IntelligenciaMercadoService::FEATURE);
        $outraFazenda = Fazenda::create(['nome' => 'Outra']);

        $this->expectException(DomainException::class);
        $this->inteligencia->cotacoesRealizadas($this->usuario, $outraFazenda->id);
    }

    /** INV-041 — combinação raça/estado com menos de 3 vendas nunca aparece. */
    public function test_combinacao_com_menos_de_3_vendas_nunca_aparece_inv041(): void
    {
        Feature::for(Fazenda::find($this->fazendaConsultante))->activate(IntelligenciaMercadoService::FEATURE);

        $this->criarVendaConfirmada('Nelore', 'MT', 3000, now()->toDateTimeString());
        $this->criarVendaConfirmada('Nelore', 'MT', 3000, now()->toDateTimeString());
        // só 2 vendas — abaixo do mínimo.

        $cotacoes = $this->inteligencia->cotacoesRealizadas($this->usuario, $this->fazendaConsultante);

        $this->assertEmpty($cotacoes, 'combinacao_com_2_vendas_fica_de_fora');
    }

    public function test_combinacao_com_3_vendas_aparece_com_preco_medio_correto_inv041(): void
    {
        Feature::for(Fazenda::find($this->fazendaConsultante))->activate(IntelligenciaMercadoService::FEATURE);

        $this->criarVendaConfirmada('Nelore', 'MT', 3000, now()->toDateTimeString());
        $this->criarVendaConfirmada('Nelore', 'MT', 4000, now()->toDateTimeString());
        $this->criarVendaConfirmada('Nelore', 'MT', 5000, now()->toDateTimeString());

        $cotacoes = $this->inteligencia->cotacoesRealizadas($this->usuario, $this->fazendaConsultante);

        $this->assertCount(1, $cotacoes);
        $this->assertSame('Nelore', $cotacoes[0]['raca']);
        $this->assertSame('MT', $cotacoes[0]['estado']);
        $this->assertEqualsWithDelta(4000.0, $cotacoes[0]['preco_medio'], 0.01, 'media_1_animal_por_venda');
        $this->assertSame(3, $cotacoes[0]['vendas_confirmadas']);
    }

    /** Preço por animal dentro de uma venda multi-animal = valor_bruto / qtd de animais (SCHEMA-CONTRATO-INTELIGENCIA-MERCADO.md §3). */
    public function test_venda_multi_animal_rateia_preco_igualmente_entre_animais(): void
    {
        Feature::for(Fazenda::find($this->fazendaConsultante))->activate(IntelligenciaMercadoService::FEATURE);

        $fazenda = Fazenda::create(['nome' => 'Lote misto', 'estado' => 'GO']);
        $a1 = Animal::create(['fazenda_id' => $fazenda->id, 'custo_aquisicao' => 1, 'status' => 'vendido', 'raca' => 'Angus']);
        $a2 = Animal::create(['fazenda_id' => $fazenda->id, 'custo_aquisicao' => 1, 'status' => 'vendido', 'raca' => 'Angus']);
        $venda = Venda::create([
            'fazenda_id' => $fazenda->id, 'chave_idempotencia' => 'lote-1', 'animal_ids' => [$a1->id, $a2->id],
            'data_venda' => now(), 'valor_bruto' => 10000, 'cpv' => 0,
            'deducao_fiscal' => 0, 'fiscal_e_premissa' => true, 'receita_liquida' => 10000,
        ]);
        $venda->animais()->attach([$a1->id, $a2->id]);

        // Preenche o mínimo de INV-041 com mais 2 vendas de 1 animal cada, mesmo preço unitário (5000) — média final tem que continuar 5000.
        $this->criarVendaConfirmada('Angus', 'GO', 5000, now()->toDateTimeString());
        $this->criarVendaConfirmada('Angus', 'GO', 5000, now()->toDateTimeString());

        $cotacoes = $this->inteligencia->cotacoesRealizadas($this->usuario, $this->fazendaConsultante);

        $this->assertCount(1, $cotacoes);
        $this->assertEqualsWithDelta(5000.0, $cotacoes[0]['preco_medio'], 0.01, 'rateio_10000_por_2_animais_bate_com_as_outras_de_5000');
        $this->assertSame(3, $cotacoes[0]['vendas_confirmadas']);
    }

    public function test_venda_fora_da_janela_de_30_dias_nunca_entra_na_media(): void
    {
        Feature::for(Fazenda::find($this->fazendaConsultante))->activate(IntelligenciaMercadoService::FEATURE);

        $this->criarVendaConfirmada('Brahman', 'BA', 1000, now()->toDateTimeString());
        $this->criarVendaConfirmada('Brahman', 'BA', 1000, now()->toDateTimeString());
        $this->criarVendaConfirmada('Brahman', 'BA', 9999999, now()->subDays(31)->toDateTimeString()); // fora da janela

        $cotacoes = $this->inteligencia->cotacoesRealizadas($this->usuario, $this->fazendaConsultante);

        // só 2 vendas dentro da janela -- abaixo do minimo do INV-041, então nem aparece.
        $this->assertEmpty($cotacoes, 'venda_de_31_dias_atras_nao_conta_deixa_combinacao_abaixo_do_minimo');
    }

    public function test_animal_sem_raca_ou_fazenda_sem_estado_nunca_aparece_na_agregacao(): void
    {
        Feature::for(Fazenda::find($this->fazendaConsultante))->activate(IntelligenciaMercadoService::FEATURE);

        $fazendaSemEstado = Fazenda::create(['nome' => 'Sem estado']); // estado null
        $animalComRaca = Animal::create(['fazenda_id' => $fazendaSemEstado->id, 'custo_aquisicao' => 1, 'status' => 'vendido', 'raca' => 'Gir']);
        $venda1 = Venda::create([
            'fazenda_id' => $fazendaSemEstado->id, 'chave_idempotencia' => 'sem-estado', 'animal_ids' => [$animalComRaca->id],
            'data_venda' => now(), 'valor_bruto' => 1000, 'cpv' => 0, 'deducao_fiscal' => 0,
            'fiscal_e_premissa' => true, 'receita_liquida' => 1000,
        ]);
        $venda1->animais()->attach($animalComRaca->id);

        $fazendaComEstado = Fazenda::create(['nome' => 'Com estado', 'estado' => 'RS']);
        $animalSemRaca = Animal::create(['fazenda_id' => $fazendaComEstado->id, 'custo_aquisicao' => 1, 'status' => 'vendido']); // raca null
        $venda2 = Venda::create([
            'fazenda_id' => $fazendaComEstado->id, 'chave_idempotencia' => 'sem-raca', 'animal_ids' => [$animalSemRaca->id],
            'data_venda' => now(), 'valor_bruto' => 1000, 'cpv' => 0, 'deducao_fiscal' => 0,
            'fiscal_e_premissa' => true, 'receita_liquida' => 1000,
        ]);
        $venda2->animais()->attach($animalSemRaca->id);

        $cotacoes = $this->inteligencia->cotacoesRealizadas($this->usuario, $this->fazendaConsultante);

        $this->assertEmpty($cotacoes, 'nenhuma_das_duas_tem_raca_e_estado_ao_mesmo_tempo');
    }

    /** Achado real de SCHEMA-CONTRATO-INTELIGENCIA-MERCADO.md §4 — uma Venda corrigida usa a linha da correção, nunca a original já superada. */
    public function test_venda_corrigida_usa_o_valor_da_correcao_nunca_o_original(): void
    {
        Feature::for(Fazenda::find($this->fazendaConsultante))->activate(IntelligenciaMercadoService::FEATURE);

        $fazenda = Fazenda::create(['nome' => 'Corrige venda', 'estado' => 'PR']);
        $a1 = Animal::create(['fazenda_id' => $fazenda->id, 'custo_aquisicao' => 1, 'status' => 'ativo', 'raca' => 'Nelore']);
        $a2 = Animal::create(['fazenda_id' => $fazenda->id, 'custo_aquisicao' => 1, 'status' => 'ativo', 'raca' => 'Nelore']);
        $usuarioVendedor = Usuario::create(['nome' => 'Vendedor']);
        Papel::create(['usuario_id' => $usuarioVendedor->id, 'fazenda_id' => $fazenda->id, 'papel' => 'dono']);

        $vendas = app(VendaService::class);
        $original = $vendas->registrar($usuarioVendedor->id, $fazenda->id, [$a1->id, $a2->id], 10000.00, now()->toDateTimeString(), 'venda-a-corrigir');
        $vendas->corrigir($usuarioVendedor->id, $original['venda']->id, [$a1->id], 4000.00, 'correcao-1'); // devolve o $a2, novo valor só pro $a1

        $this->criarVendaConfirmada('Nelore', 'PR', 4000, now()->toDateTimeString());
        $this->criarVendaConfirmada('Nelore', 'PR', 4000, now()->toDateTimeString());

        $cotacoes = $this->inteligencia->cotacoesRealizadas($this->usuario, $this->fazendaConsultante);

        $this->assertCount(1, $cotacoes);
        // Se o original (10000/2 animais = 5000/animal) tivesse entrado junto
        // com a correção, a média teria subido acima de 4000.
        $this->assertEqualsWithDelta(4000.0, $cotacoes[0]['preco_medio'], 0.01, 'usa_so_a_correcao_nunca_o_original_superado');
        $this->assertSame(3, $cotacoes[0]['vendas_confirmadas']);
    }
}
