<?php

namespace Tests\Feature\VerticalConfiguracaoFiscal;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\LancamentoFiscal;
use App\Models\Lote;
use App\Models\Papel;
use App\Models\ResponsavelFiscal;
use App\Models\Usuario;
use App\Services\ConfiguracaoFiscalService;
use App\Services\VendaService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 29 (Configuração Fiscal) — nasce de
 * VERTICAL-CONFIGURACAO-FISCAL.md e SCHEMA-CONTRATO-CONFIGURACAO-FISCAL.md.
 * Fecha o achado mais antigo do laboratório (LAB-SA-001/013/021):
 * lancamentos_fiscais nunca alimentada pelo uso normal do sistema.
 */
class ConfiguracaoFiscalDominioTest extends TestCase
{
    use RefreshDatabase;

    private ConfiguracaoFiscalService $configuracaoFiscalService;

    private VendaService $vendaService;

    private int $fazendaId;

    private int $usuarioId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuracaoFiscalService = app(ConfiguracaoFiscalService::class);
        $this->vendaService = app(VendaService::class);

        $this->fazendaId = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->usuarioId = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->usuarioId, 'fazenda_id' => $this->fazendaId, 'papel' => 'dono']);
    }

    private function criarAnimalAtivo(): int
    {
        return Animal::create(['fazenda_id' => $this->fazendaId, 'custo_aquisicao' => 1000, 'status' => 'ativo'])->id;
    }

    public function test_definir_taxa(): void
    {
        $responsavel = $this->configuracaoFiscalService->definirTaxa($this->usuarioId, $this->fazendaId, 0.023);

        $this->assertEquals(0.023, (float) $responsavel->taxa_venda_animal);
        $this->assertFalse($responsavel->taxa_e_premissa);
        $this->assertSame($this->usuarioId, $responsavel->usuario_id);
    }

    public function test_definir_taxa_fora_da_faixa_e_recusado(): void
    {
        $this->expectException(DomainException::class);
        $this->configuracaoFiscalService->definirTaxa($this->usuarioId, $this->fazendaId, 1.5);
    }

    public function test_definir_taxa_negativa_e_recusada(): void
    {
        $this->expectException(DomainException::class);
        $this->configuracaoFiscalService->definirTaxa($this->usuarioId, $this->fazendaId, -0.01);
    }

    public function test_definir_taxa_sem_relacao_com_fazenda_e_recusado(): void
    {
        $outroUsuarioId = Usuario::create(['nome' => 'Maria'])->id;

        $this->expectException(DomainException::class);
        $this->configuracaoFiscalService->definirTaxa($outroUsuarioId, $this->fazendaId, 0.023);
    }

    public function test_definir_taxa_reaproveita_o_mesmo_registro(): void
    {
        $this->configuracaoFiscalService->definirTaxa($this->usuarioId, $this->fazendaId, 0.023);
        $this->configuracaoFiscalService->definirTaxa($this->usuarioId, $this->fazendaId, 0.05);

        $this->assertSame(1, ResponsavelFiscal::count());
        $this->assertEquals(0.05, (float) ResponsavelFiscal::find($this->fazendaId)->taxa_venda_animal);
    }

    /** INV-061 — toda Venda ganha um LancamentoFiscal, mesma transação. */
    public function test_venda_sem_taxa_configurada_gera_lancamento_fiscal_com_deducao_zero(): void
    {
        $animal = $this->criarAnimalAtivo();

        $resultado = $this->vendaService->registrar($this->usuarioId, $this->fazendaId, [$animal], 1000.0, '2026-01-01 10:00:00', 'venda-1');

        $lancamento = LancamentoFiscal::where('venda_id', $resultado['venda']->id)->first();
        $this->assertNotNull($lancamento, 'toda Venda precisa gerar um LancamentoFiscal');
        $this->assertEquals(0.0, (float) $lancamento->deducao_fiscal);
        $this->assertNull($lancamento->taxa_aplicada);
        $this->assertTrue($lancamento->fiscal_e_premissa);
    }

    public function test_venda_com_taxa_configurada_gera_lancamento_fiscal_com_deducao_real(): void
    {
        $this->configuracaoFiscalService->definirTaxa($this->usuarioId, $this->fazendaId, 0.10);
        $animal = $this->criarAnimalAtivo();

        $resultado = $this->vendaService->registrar($this->usuarioId, $this->fazendaId, [$animal], 1000.0, '2026-01-01 10:00:00', 'venda-1');

        $lancamento = LancamentoFiscal::where('venda_id', $resultado['venda']->id)->first();
        $this->assertEquals(100.0, (float) $lancamento->deducao_fiscal);
        $this->assertEquals(0.10, (float) $lancamento->taxa_aplicada);
        $this->assertFalse($lancamento->fiscal_e_premissa);
        $this->assertEquals(100.0, (float) $resultado['deducao_fiscal']);
    }

    /** INV-062 — valores congelados, uma Venda antiga nunca muda se a taxa for reconfigurada depois. */
    public function test_reconfigurar_taxa_nao_altera_lancamento_fiscal_ja_criado(): void
    {
        $this->configuracaoFiscalService->definirTaxa($this->usuarioId, $this->fazendaId, 0.10);
        $animal = $this->criarAnimalAtivo();
        $resultado = $this->vendaService->registrar($this->usuarioId, $this->fazendaId, [$animal], 1000.0, '2026-01-01 10:00:00', 'venda-1');

        $this->configuracaoFiscalService->definirTaxa($this->usuarioId, $this->fazendaId, 0.50);

        $lancamento = LancamentoFiscal::where('venda_id', $resultado['venda']->id)->first();
        $this->assertEquals(0.10, (float) $lancamento->taxa_aplicada);
        $this->assertEquals(100.0, (float) $lancamento->deducao_fiscal);
    }

    /** INV-026/INV-061 — a correção é seu próprio fato, ganha seu próprio LancamentoFiscal. */
    public function test_corrigir_venda_gera_seu_proprio_lancamento_fiscal(): void
    {
        $this->configuracaoFiscalService->definirTaxa($this->usuarioId, $this->fazendaId, 0.10);
        $lote = Lote::create(['fazenda_id' => $this->fazendaId, 'qtd_animais' => 2, 'custo_aquisicao' => 1000]);
        $animal1 = Animal::create(['fazenda_id' => $this->fazendaId, 'lote_id' => $lote->id, 'status' => 'ativo'])->id;
        $animal2 = Animal::create(['fazenda_id' => $this->fazendaId, 'lote_id' => $lote->id, 'status' => 'ativo'])->id;
        $original = $this->vendaService->registrar($this->usuarioId, $this->fazendaId, [$animal1, $animal2], 2000.0, '2026-01-01 10:00:00', 'venda-1')['venda'];

        $correcao = $this->vendaService->corrigir($this->usuarioId, $original->id, [$animal1], 1000.0, 'correcao-1');

        $lancamentoOriginal = LancamentoFiscal::where('venda_id', $original->id)->first();
        $lancamentoCorrecao = LancamentoFiscal::where('venda_id', $correcao['correcao']->id)->first();
        $this->assertNotNull($lancamentoOriginal);
        $this->assertNotNull($lancamentoCorrecao);
        $this->assertNotSame($lancamentoOriginal->id, $lancamentoCorrecao->id);
        $this->assertEquals(100.0, (float) $lancamentoCorrecao->deducao_fiscal);
    }

    public function test_venda_sem_responsavel_fiscal_nunca_bloqueia_a_venda(): void
    {
        $animal = $this->criarAnimalAtivo();

        $resultado = $this->vendaService->registrar($this->usuarioId, $this->fazendaId, [$animal], 1000.0, '2026-01-01 10:00:00', 'venda-1');

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertEquals(0.0, (float) $resultado['deducao_fiscal']);
    }
}
