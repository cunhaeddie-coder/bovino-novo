<?php

namespace Tests\Feature\VerticalVenda;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Lote;
use App\Models\Papel;
use App\Models\ResponsavelFiscal;
use App\Models\Usuario;
use App\Models\Venda;
use App\Services\OutboxService;
use App\Services\VendaService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reexecução do Spike 006 (bovino-lab/spikes/006-teste-dominio-vertical-venda)
 * contra a implementação real — Fase 4, passo 6 do roteiro. Nasce de
 * VERTICAL-VENDA.md, seções 1-9, não do schema. As 24 checagens (a1-a16,
 * b1-b8) são as MESMAS do spike original, traduzidas de asserção livre em
 * PHP puro pra PHPUnit — nenhuma condição foi afrouxada ou embelezada.
 *
 * Um teste a mais no fim (c1) cobre o risco assumido em
 * SCHEMA-CONTRATO-VENDA.md §12: isolamento no consumidor do outbox, não
 * testado pelo Spike 006 original (o spike só chamava um stub síncrono).
 */
class Spike006ReexecucaoTest extends TestCase
{
    use RefreshDatabase;

    private VendaService $vendas;

    private int $fazendaA;

    private int $fazendaB;

    private int $jose;

    private int $mariazinha;

    private int $loteA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendas = app(VendaService::class);

        // ── Mundo: Fazenda A (José) e Fazenda B (Mariazinha) — alvo do ataque de isolamento ──
        $this->fazendaA = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $this->fazendaB = Fazenda::create(['nome' => 'Chácara da Mariazinha'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        $this->mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $this->mariazinha, 'fazenda_id' => $this->fazendaB, 'papel' => 'dono']);

        $this->loteA = $this->semearLote($this->fazendaA, 150, 524000.00); // LAB-SA-002
        $loteB = $this->semearLote($this->fazendaB, 10, 35000.00); // alvo do ataque de isolamento

        // Fiscal mínimo — taxa É PREMISSA, marcada, não inventada como fato.
        ResponsavelFiscal::create(['fazenda_id' => $this->fazendaA, 'usuario_id' => $this->jose, 'taxa_venda_animal' => 0.023, 'taxa_e_premissa' => true]); // [PREMISSA PENDENTE]
        ResponsavelFiscal::create(['fazenda_id' => $this->fazendaB, 'usuario_id' => $this->mariazinha, 'taxa_venda_animal' => null, 'taxa_e_premissa' => true]);
    }

    private function semearLote(int $fazendaId, int $qtd, float $custo): int
    {
        $lote = Lote::create(['fazenda_id' => $fazendaId, 'qtd_animais' => $qtd, 'custo_aquisicao' => $custo]);
        for ($i = 0; $i < $qtd; $i++) {
            Animal::create(['fazenda_id' => $fazendaId, 'lote_id' => $lote->id, 'status' => 'ativo']);
        }

        return $lote->id;
    }

    public function test_caso_a_venda_normal_e_ataques_de_isolamento(): void
    {
        $this->executarCasoA();
    }

    private function executarCasoA(): array
    {
        $idsFazendaA = Animal::where('fazenda_id', $this->fazendaA)->orderBy('id')->limit(28)->pluck('id')->all();
        $idsFazendaB = Animal::where('fazenda_id', $this->fazendaB)->pluck('id')->all();

        $venda = $this->vendas->registrar($this->jose, $this->fazendaA, $idsFazendaA, 99999.76, '2026-01-15 10:00:00', 'venda-jose-28vacas-2026-01-15');

        // a1/a2
        $vendaLida = $this->vendas->buscar($this->jose, $venda['venda']->id);
        $this->assertNotNull($vendaLida, 'a1_venda_existe');
        $this->assertSame($this->fazendaA, $vendaLida->fazenda_id, 'a2_fazenda_correta_e_dona');

        // a3/a4
        $vendidosA = Animal::where('fazenda_id', $this->fazendaA)->where('status', 'vendido')->count();
        $ativosA = Animal::where('fazenda_id', $this->fazendaA)->where('status', 'ativo')->count();
        $this->assertSame(28, $vendidosA, 'a3_28_animais_indisponiveis');
        $this->assertSame(122, $ativosA, 'a4_122_ativos_remanescentes');

        // a5
        $ativosB = Animal::where('fazenda_id', $this->fazendaB)->where('status', 'ativo')->count();
        $this->assertSame(10, $ativosB, 'a5_fazenda_b_intacta');

        // a6 — INV-001, patrimônio recalculado
        $loteAAtual = Lote::find($this->loteA);
        $custoEsperado = round(524000.00 * 122 / 150, 2);
        $this->assertEqualsWithDelta($custoEsperado, (float) $loteAAtual->custo_aquisicao, 0.02, 'a6_patrimonio_recalculado_inv001');

        // a7
        $cpvEsperado = round(524000.00 * 28 / 150, 2);
        $this->assertEqualsWithDelta($cpvEsperado, $venda['cpv'], 0.02, 'a7_cpv_correto');

        // a8 — VERTICAL-VENDA.md exige "reconhecida", nunca "positiva" (seção 11: pode ser negativa).
        $this->assertIsNumeric((string) $vendaLida->receita_liquida, 'a8_receita_reconhecida');

        // a9
        $this->assertEqualsWithDelta(round(99999.76 * 0.023, 2), $venda['deducao_fiscal'], 0.02, 'a9_deducao_fiscal_usa_responsavel_e_regra');

        // a10
        $eventoRow = EventoDominio::where('fazenda_id', $this->fazendaA)->where('tipo', 'venda_concluida')->first();
        $this->assertNotNull($eventoRow, 'a10_evento_dominio_existe');
        $this->assertSame('pendente', $eventoRow->status_consequencia, 'a10_evento_dominio_existe');

        // a11 — a consequência não pode alterar o fato histórico.
        $snapshotVendaAntes = $vendaLida->refresh()->toArray();
        $snapshotLoteAntes = Lote::find($this->loteA)->toArray();
        app(OutboxService::class)->processar($eventoRow);
        $this->assertSame($snapshotVendaAntes, $this->vendas->buscar($this->jose, $venda['venda']->id)->toArray(), 'a11_consequencia_nao_altera_fato_historico (venda)');
        $this->assertSame($snapshotLoteAntes, Lote::find($this->loteA)->toArray(), 'a11_consequencia_nao_altera_fato_historico (lote)');

        // a12
        $this->assertTrue(
            $vendaLida->valor_bruto > 0 && $vendaLida->cpv > 0 && $vendaLida->receita_liquida != 0 && $vendaLida->fiscal_e_premissa === true,
            'a12_nada_descartado_silenciosamente'
        );

        // ── Ataque de isolamento embutido no Caso A ──

        // a13
        $this->assertNull($this->vendas->buscar($this->mariazinha, $venda['venda']->id), 'a13_mariazinha_nao_le_venda_de_jose');

        // a14
        $this->assertThrows(fn () => $this->vendas->registrar($this->mariazinha, $this->fazendaA, [$idsFazendaA[0]], 500.00, '2026-01-15 10:00:00', 'ataque-mariazinha-vende-fazenda-a'), DomainException::class);

        // a15
        $this->assertThrows(fn () => $this->vendas->registrar($this->jose, $this->fazendaB, [$idsFazendaB[0]], 500.00, '2026-01-15 10:00:00', 'ataque-jose-vende-fazenda-b'), DomainException::class);

        // a16 — José tem relação com a Fazenda A, mas tenta misturar animal da Fazenda B numa venda da A.
        $this->assertThrows(fn () => $this->vendas->registrar($this->jose, $this->fazendaA, [$idsFazendaB[1]], 500.00, '2026-01-15 10:00:00', 'ataque-jose-mistura-animal-da-b'), DomainException::class);

        return ['venda' => $venda, 'idsFazendaA' => $idsFazendaA];
    }

    public function test_caso_b_correcao_28_para_26_e_ataque_de_isolamento(): void
    {
        ['venda' => $venda] = $this->executarCasoA();

        $idsVendidos = $venda['ids_vendidos'];
        $idsCorrigidos = array_slice($idsVendidos, 0, 26);
        $idsQueDeveriamSair = array_slice($idsVendidos, 26, 2);
        $novoValor = round(99999.76 * 26 / 28, 2);

        $correcao = $this->vendas->corrigir($this->jose, $venda['venda']->id, $idsCorrigidos, $novoValor, 'correcao-jose-28para26');

        // b1/b2 — venda original imutável e continua existindo.
        $originalAindaExiste = $this->vendas->buscar($this->jose, $venda['venda']->id);
        $this->assertNotNull($originalAindaExiste, 'b1_venda_original_continua_existindo');
        $this->assertEqualsWithDelta(99999.76, (float) $originalAindaExiste->valor_bruto, 0.01, 'b2_venda_original_nao_foi_sobrescrita');
        $this->assertNull($originalAindaExiste->venda_original_id, 'b2_venda_original_nao_foi_sobrescrita');

        // b3
        $correcaoLida = $this->vendas->buscar($this->jose, $correcao['correcao']->id);
        $this->assertNotNull($correcaoLida, 'b3_correcao_referencia_original');
        $this->assertSame($venda['venda']->id, $correcaoLida->venda_original_id, 'b3_correcao_referencia_original');

        // b4
        $this->assertSame($idsQueDeveriamSair, $correcao['ids_que_saem'], 'b4_dois_animais_saem_da_venda');

        // b5
        $statusAnimaisQueSairam = Animal::whereIn('id', $idsQueDeveriamSair)->pluck('status')->all();
        $this->assertSame(['ativo', 'ativo'], $statusAnimaisQueSairam, 'b5_animais_excluidos_voltam_a_ativo');

        // b6
        $cpvEsperado = round(524000.00 * 28 / 150, 2);
        $this->assertEqualsWithDelta(round($cpvEsperado * 26 / 28, 2), $correcao['novo_cpv'], 0.05, 'b6_valores_recalculados');

        // b7
        $eventoCorrecao = EventoDominio::where('tipo', 'venda_corrigida')->first();
        $this->assertNotNull($eventoCorrecao, 'b7_novo_fato_registrado_como_evento');

        // ── Ataque de isolamento embutido no Caso B ──
        // b8
        $this->assertThrows(
            fn () => $this->vendas->corrigir($this->mariazinha, $venda['venda']->id, $idsCorrigidos, $novoValor, 'ataque-mariazinha-corrige-venda-de-jose'),
            DomainException::class
        );
    }

    /**
     * c1 — novo, não parte do Spike 006 original. Fecha o risco assumido em
     * SCHEMA-CONTRATO-VENDA.md §12: o consumidor do outbox processa o evento
     * de uma Fazenda sem tocar o estado de outra.
     */
    public function test_c1_consumidor_do_outbox_preserva_isolamento_entre_fazendas(): void
    {
        $idsFazendaA = Animal::where('fazenda_id', $this->fazendaA)->orderBy('id')->limit(5)->pluck('id')->all();
        $idsFazendaB = Animal::where('fazenda_id', $this->fazendaB)->orderBy('id')->limit(3)->pluck('id')->all();

        $this->vendas->registrar($this->jose, $this->fazendaA, $idsFazendaA, 10000.00, '2026-01-15 10:00:00', 'venda-a-consumidor');
        $this->vendas->registrar($this->mariazinha, $this->fazendaB, $idsFazendaB, 5000.00, '2026-01-15 10:00:00', 'venda-b-consumidor');

        $eventoA = EventoDominio::where('fazenda_id', $this->fazendaA)->firstOrFail();
        $eventoB = EventoDominio::where('fazenda_id', $this->fazendaB)->firstOrFail();

        app(OutboxService::class)->processar($eventoA);

        $this->assertSame('concluido', $eventoA->fresh()->status_consequencia, 'c1_evento_da_fazenda_a_processado');
        $this->assertSame('pendente', $eventoB->fresh()->status_consequencia, 'c1_evento_da_fazenda_b_intocado');
    }
}
