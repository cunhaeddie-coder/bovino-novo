<?php

namespace Tests\Feature\VerticalCarteira;

use App\Models\Animal;
use App\Models\Conta;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Lancamento;
use App\Models\Papel;
use App\Models\Titular;
use App\Models\Usuario;
use App\Services\CompraService;
use App\Services\ContaService;
use App\Services\LancamentoService;
use App\Services\OutboxService;
use App\Services\VendaService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 27 (Carteira/Conta) — nasce de
 * VERTICAL-CARTEIRA.md e SCHEMA-CONTRATO-CARTEIRA.md. Cobre a ação direta
 * do produtor (Conta externa + Lançamento manual) e a consequência
 * automática (forma_pagamento_liquidada → Lançamento na Conta bovino).
 */
class CarteiraDominioTest extends TestCase
{
    use RefreshDatabase;

    private ContaService $contaService;

    private LancamentoService $lancamentoService;

    private int $titularId;

    private int $fazendaId;

    private int $usuarioId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contaService = app(ContaService::class);
        $this->lancamentoService = app(LancamentoService::class);

        $this->titularId = Titular::create(['documento' => '11222333000181', 'tipo_documento' => 'cnpj'])->id;
        $this->fazendaId = Fazenda::create(['nome' => 'Fazenda Alegria', 'titular_id' => $this->titularId])->id;
        $this->usuarioId = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->usuarioId, 'fazenda_id' => $this->fazendaId, 'papel' => 'dono']);
    }

    // ── ContaService::criarExterna ─────────────────────────────────────

    public function test_criar_conta_externa(): void
    {
        $resultado = $this->contaService->criarExterna($this->usuarioId, $this->titularId, 'Banco do Brasil', 'chave-1');

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertSame('externa', $resultado['conta']->tipo);
        $this->assertSame('Banco do Brasil', $resultado['conta']->nome);
    }

    public function test_criar_conta_externa_reenvio_nao_duplica(): void
    {
        $r1 = $this->contaService->criarExterna($this->usuarioId, $this->titularId, 'Banco', 'chave-1');
        $r2 = $this->contaService->criarExterna($this->usuarioId, $this->titularId, 'Banco', 'chave-1');

        $this->assertTrue($r2['reenvio_detectado']);
        $this->assertSame($r1['conta']->id, $r2['conta']->id);
        $this->assertSame(1, Conta::count());
    }

    public function test_criar_conta_externa_sem_relacao_com_titular_e_recusado(): void
    {
        $outroUsuario = Usuario::create(['nome' => 'Maria'])->id;

        $this->expectException(DomainException::class);
        $this->contaService->criarExterna($outroUsuario, $this->titularId, 'Banco', 'chave-1');
    }

    public function test_criar_conta_externa_nome_vazio_e_recusado(): void
    {
        $this->expectException(DomainException::class);
        $this->contaService->criarExterna($this->usuarioId, $this->titularId, '   ', 'chave-1');
    }

    public function test_usuario_de_outra_fazenda_do_mesmo_titular_pode_criar_conta(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Fazenda Irmã', 'titular_id' => $this->titularId]);
        $outroUsuario = Usuario::create(['nome' => 'Maria'])->id;
        Papel::create(['usuario_id' => $outroUsuario, 'fazenda_id' => $outraFazenda->id, 'papel' => 'dono']);

        $resultado = $this->contaService->criarExterna($outroUsuario, $this->titularId, 'Banco', 'chave-1');

        $this->assertFalse($resultado['reenvio_detectado']);
    }

    // ── LancamentoService::lancarManual ────────────────────────────────

    public function test_lancar_manual_em_conta_externa(): void
    {
        $conta = $this->contaService->criarExterna($this->usuarioId, $this->titularId, 'Banco', 'chave-1')['conta'];

        $resultado = $this->lancamentoService->lancarManual($this->usuarioId, $conta->id, 'entrada', 500.0, '2026-01-01 10:00:00', 'Depósito', 'chave-lanc-1');

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertSame('manual', $resultado['lancamento']->origem);
        $this->assertEquals(500.0, $conta->fresh()->saldo);
    }

    public function test_lancar_manual_reenvio_nao_duplica(): void
    {
        $conta = $this->contaService->criarExterna($this->usuarioId, $this->titularId, 'Banco', 'chave-1')['conta'];

        $this->lancamentoService->lancarManual($this->usuarioId, $conta->id, 'entrada', 500.0, '2026-01-01', 'Depósito', 'chave-lanc-1');
        $r2 = $this->lancamentoService->lancarManual($this->usuarioId, $conta->id, 'entrada', 500.0, '2026-01-01', 'Depósito', 'chave-lanc-1');

        $this->assertTrue($r2['reenvio_detectado']);
        $this->assertSame(1, Lancamento::count());
    }

    public function test_lancar_manual_em_conta_bovino_e_recusado(): void
    {
        // Conta bovino nasce sozinha (nunca por ação do produtor) — cria via ContaService pra simular o cenário.
        $contaBovino = $this->contaService->buscarOuCriarContaBovino($this->titularId);

        $this->expectException(DomainException::class);
        $this->lancamentoService->lancarManual($this->usuarioId, $contaBovino->id, 'entrada', 500.0, '2026-01-01', 'x', 'chave-1');
    }

    public function test_lancar_manual_valor_zero_e_recusado(): void
    {
        $conta = $this->contaService->criarExterna($this->usuarioId, $this->titularId, 'Banco', 'chave-1')['conta'];

        $this->expectException(DomainException::class);
        $this->lancamentoService->lancarManual($this->usuarioId, $conta->id, 'entrada', 0.0, '2026-01-01', 'x', 'chave-2');
    }

    public function test_lancar_manual_sem_relacao_com_titular_e_recusado(): void
    {
        $conta = $this->contaService->criarExterna($this->usuarioId, $this->titularId, 'Banco', 'chave-1')['conta'];
        $outroUsuario = Usuario::create(['nome' => 'Maria'])->id;

        $this->expectException(DomainException::class);
        $this->lancamentoService->lancarManual($outroUsuario, $conta->id, 'entrada', 500.0, '2026-01-01', 'x', 'chave-2');
    }

    // ── Consumidor de outbox (forma_pagamento_liquidada → Lançamento) ─

    private function criarAnimalAtivo(int $fazendaId): int
    {
        return Animal::create(['fazenda_id' => $fazendaId, 'custo_aquisicao' => 1000, 'status' => 'ativo'])->id;
    }

    public function test_venda_a_vista_gera_lancamento_automatico_na_conta_bovino(): void
    {
        $animal = $this->criarAnimalAtivo($this->fazendaId);
        app(VendaService::class)->registrar($this->usuarioId, $this->fazendaId, [$animal], 5000.0, '2026-01-01 10:00:00', 'venda-1');

        app(OutboxService::class)->processarPendentes();

        $conta = Conta::where('titular_id', $this->titularId)->where('tipo', 'bovino')->first();
        $this->assertNotNull($conta, 'a Conta bovino precisa ter sido criada sozinha');
        $this->assertSame(1, $conta->lancamentos()->count());
        $lancamento = $conta->lancamentos()->first();
        $this->assertSame('entrada', $lancamento->tipo);
        $this->assertEquals(5000.0, (float) $lancamento->valor);
        $this->assertSame('automatico', $lancamento->origem);
        $this->assertStringContainsString('Venda', $lancamento->descricao);
    }

    public function test_compra_a_vista_gera_lancamento_de_saida(): void
    {
        $fornecedor = Fornecedor::create(['nome' => 'Marília'])->id;
        app(CompraService::class)->registrar($this->usuarioId, $this->fazendaId, $fornecedor, [3000.0], '2026-01-01', 'compra-1');

        app(OutboxService::class)->processarPendentes();

        $conta = Conta::where('titular_id', $this->titularId)->where('tipo', 'bovino')->first();
        $lancamento = $conta->lancamentos()->first();
        $this->assertSame('saida', $lancamento->tipo);
        $this->assertEquals(3000.0, (float) $lancamento->valor);
    }

    public function test_fazenda_sem_titular_nao_gera_lancamento_e_nao_e_erro(): void
    {
        $fazendaSemTitular = Fazenda::create(['nome' => 'Sem Titular']);
        $usuario = Usuario::create(['nome' => 'Outro'])->id;
        Papel::create(['usuario_id' => $usuario, 'fazenda_id' => $fazendaSemTitular->id, 'papel' => 'dono']);
        $animal = $this->criarAnimalAtivo($fazendaSemTitular->id);

        app(VendaService::class)->registrar($usuario, $fazendaSemTitular->id, [$animal], 1000.0, '2026-01-01 10:00:00', 'venda-x');

        $processados = app(OutboxService::class)->processarPendentes();

        $this->assertSame(0, Conta::count());
        $this->assertSame(0, Lancamento::count());
        $this->assertTrue($processados->every(fn (EventoDominio $e) => $e->status_consequencia === 'concluido'));
    }

    public function test_reprocessar_o_mesmo_evento_nunca_duplica_o_lancamento(): void
    {
        $animal = $this->criarAnimalAtivo($this->fazendaId);
        app(VendaService::class)->registrar($this->usuarioId, $this->fazendaId, [$animal], 5000.0, '2026-01-01 10:00:00', 'venda-1');

        $evento = EventoDominio::where('tipo', 'forma_pagamento_liquidada')->firstOrFail();
        app(OutboxService::class)->processar($evento);
        // reprocessamento deliberado do mesmo evento (varredura pegando de novo antes do status atualizar, ou reenvio manual)
        app(OutboxService::class)->processar($evento->fresh());

        $this->assertSame(1, Lancamento::where('forma_pagamento_id', $evento->payload['forma_pagamento_id'])->count());
    }

    public function test_saldo_consolidado_do_titular_soma_todas_as_contas(): void
    {
        $contaExterna = $this->contaService->criarExterna($this->usuarioId, $this->titularId, 'Banco', 'chave-1')['conta'];
        $this->lancamentoService->lancarManual($this->usuarioId, $contaExterna->id, 'entrada', 2000.0, '2026-01-01', 'x', 'chave-2');

        $animal = $this->criarAnimalAtivo($this->fazendaId);
        app(VendaService::class)->registrar($this->usuarioId, $this->fazendaId, [$animal], 5000.0, '2026-01-01 10:00:00', 'venda-1');
        app(OutboxService::class)->processarPendentes();

        $saldoConsolidado = Titular::find($this->titularId)->contas->sum('saldo');

        $this->assertEquals(7000.0, $saldoConsolidado);
    }

    public function test_duas_fazendas_do_mesmo_titular_alimentam_a_mesma_conta_bovino(): void
    {
        $outraFazendaId = Fazenda::create(['nome' => 'Fazenda Irmã', 'titular_id' => $this->titularId])->id;
        Papel::create(['usuario_id' => $this->usuarioId, 'fazenda_id' => $outraFazendaId, 'papel' => 'dono']);

        $animal1 = $this->criarAnimalAtivo($this->fazendaId);
        $animal2 = $this->criarAnimalAtivo($outraFazendaId);
        app(VendaService::class)->registrar($this->usuarioId, $this->fazendaId, [$animal1], 5000.0, '2026-01-01 10:00:00', 'venda-1');
        app(VendaService::class)->registrar($this->usuarioId, $outraFazendaId, [$animal2], 3000.0, '2026-01-01 10:00:00', 'venda-2');
        app(OutboxService::class)->processarPendentes();

        $this->assertSame(1, Conta::where('titular_id', $this->titularId)->where('tipo', 'bovino')->count());
        $conta = Conta::where('titular_id', $this->titularId)->where('tipo', 'bovino')->first();
        $this->assertEquals(8000.0, $conta->saldo);
    }
}
