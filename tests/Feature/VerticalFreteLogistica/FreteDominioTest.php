<?php

namespace Tests\Feature\VerticalFreteLogistica;

use App\Models\ComissaoPlataforma;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Motorista;
use App\Models\ObrigacaoFinanceira;
use App\Models\OrdemFrete;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ConfiguracaoPlataformaService;
use App\Services\MotoristaService;
use App\Services\OrdemFreteService;
use App\Services\PropostaFreteService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 22 (Frete/Logística) — nasce de
 * VERTICAL-FRETE-LOGISTICA.md e SCHEMA-CONTRATO-FRETE-LOGISTICA.md.
 * Reproduz LAB-FA-027 (leilão + contratação direta) e LAB-FA-029 (comissão,
 * mecanismo sólido).
 */
class FreteDominioTest extends TestCase
{
    use RefreshDatabase;

    private MotoristaService $motoristas;

    private OrdemFreteService $ordens;

    private PropostaFreteService $propostas;

    private ConfiguracaoPlataformaService $config;

    private int $fazendaId;

    private int $usuarioId;

    private int $administradorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->motoristas = app(MotoristaService::class);
        $this->ordens = app(OrdemFreteService::class);
        $this->propostas = app(PropostaFreteService::class);
        $this->config = app(ConfiguracaoPlataformaService::class);

        $this->fazendaId = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->usuarioId = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->usuarioId, 'fazenda_id' => $this->fazendaId, 'papel' => 'dono']);
        $this->administradorId = Usuario::create(['nome' => 'Admin', 'eh_administrador' => true])->id;
    }

    private function criarMotoristaAprovado(?int $fazendaId = null): Motorista
    {
        $usuario = Usuario::create(['nome' => 'Motorista '.uniqid()]);
        $motorista = $this->motoristas->cadastrar($usuario->id, '000.000.000-00', $fazendaId);

        return $this->motoristas->aprovar($this->administradorId, $motorista->id);
    }

    // ── MotoristaService ────────────────────────────────────────────────

    public function test_motorista_nasce_pendente(): void
    {
        $usuario = Usuario::create(['nome' => 'M']);
        $motorista = $this->motoristas->cadastrar($usuario->id, '111');

        $this->assertSame('pendente', $motorista->status);
    }

    public function test_documento_vazio_e_recusado(): void
    {
        $usuario = Usuario::create(['nome' => 'M']);

        $this->expectException(DomainException::class);
        $this->motoristas->cadastrar($usuario->id, '   ');
    }

    public function test_aprovar_motorista_exige_administrador(): void
    {
        $usuario = Usuario::create(['nome' => 'M']);
        $motorista = $this->motoristas->cadastrar($usuario->id, '111');

        $this->expectException(DomainException::class);
        $this->motoristas->aprovar($this->usuarioId, $motorista->id); // não é administrador
    }

    public function test_aprovar_motorista_grava_status_e_quem_aprovou(): void
    {
        $usuario = Usuario::create(['nome' => 'M']);
        $motorista = $this->motoristas->cadastrar($usuario->id, '111');

        $aprovado = $this->motoristas->aprovar($this->administradorId, $motorista->id);

        $this->assertSame('aprovado', $aprovado->status);
        $this->assertSame($this->administradorId, $aprovado->aprovado_por);
        $this->assertNotNull($aprovado->aprovado_em);
    }

    public function test_reprovar_motorista_muda_status(): void
    {
        $usuario = Usuario::create(['nome' => 'M']);
        $motorista = $this->motoristas->cadastrar($usuario->id, '111');

        $reprovado = $this->motoristas->reprovar($this->administradorId, $motorista->id);

        $this->assertSame('reprovado', $reprovado->status);
    }

    // ── ConfiguracaoPlataformaService ───────────────────────────────────

    public function test_percentual_comissao_frete_comeca_em_10_por_cento(): void
    {
        $this->assertSame(10.0, $this->config->percentualComissaoFrete());
    }

    public function test_definir_comissao_exige_administrador(): void
    {
        $this->expectException(DomainException::class);
        $this->config->definirComissaoFrete($this->usuarioId, 15);
    }

    public function test_definir_comissao_recusa_percentual_fora_do_intervalo(): void
    {
        $this->expectException(DomainException::class);
        $this->config->definirComissaoFrete($this->administradorId, 0);
    }

    public function test_definir_comissao_muda_o_percentual_vigente(): void
    {
        $this->config->definirComissaoFrete($this->administradorId, 7.5);

        $this->assertSame(7.5, $this->config->percentualComissaoFrete());
    }

    // ── OrdemFreteService — solicitar / leilão ──────────────────────────

    public function test_solicitar_cria_ordem_aguardando_lance(): void
    {
        $resultado = $this->ordens->solicitar($this->usuarioId, $this->fazendaId, 'ordem-1');

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertSame('aguardando_lance', $resultado['ordem_frete']->status);
    }

    public function test_solicitar_e_idempotente_por_chave(): void
    {
        $r1 = $this->ordens->solicitar($this->usuarioId, $this->fazendaId, 'ordem-dup');
        $r2 = $this->ordens->solicitar($this->usuarioId, $this->fazendaId, 'ordem-dup');

        $this->assertFalse($r1['reenvio_detectado']);
        $this->assertTrue($r2['reenvio_detectado']);
        $this->assertSame($r1['ordem_frete']->id, $r2['ordem_frete']->id);
        $this->assertSame(1, OrdemFrete::count());
    }

    public function test_solicitar_chave_vazia_e_recusada(): void
    {
        $this->expectException(DomainException::class);
        $this->ordens->solicitar($this->usuarioId, $this->fazendaId, '');
    }

    public function test_solicitar_sem_relacao_com_fazenda_e_recusado(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Outra']);

        $this->expectException(DomainException::class);
        $this->ordens->solicitar($this->usuarioId, $outraFazenda->id, 'ordem-x');
    }

    // ── PropostaFreteService — leilão ────────────────────────────────────

    public function test_dar_lance_exige_motorista_aprovado_inv042(): void
    {
        $ordem = $this->ordens->solicitar($this->usuarioId, $this->fazendaId, 'ordem-lance')['ordem_frete'];
        $usuarioMotorista = Usuario::create(['nome' => 'Motorista pendente']);
        $this->motoristas->cadastrar($usuarioMotorista->id, '222'); // nunca aprovado

        $this->expectException(DomainException::class);
        $this->propostas->darLance($usuarioMotorista->id, $ordem->id, 5000);
    }

    public function test_dar_lance_so_funciona_em_ordem_aguardando_lance(): void
    {
        $motorista = $this->criarMotoristaAprovado();
        $ordem = $this->ordens->contratarDireto($this->usuarioId, $this->fazendaId, $motorista->id, 3000, 'ordem-ja-aceita')['ordem_frete'];

        $outroMotorista = $this->criarMotoristaAprovado();
        $this->expectException(DomainException::class);
        $this->propostas->darLance($outroMotorista->usuario_id, $ordem->id, 2500);
    }

    public function test_aceitar_proposta_grava_motorista_e_valor_na_ordem(): void
    {
        $ordem = $this->ordens->solicitar($this->usuarioId, $this->fazendaId, 'ordem-aceite')['ordem_frete'];
        $motorista = $this->criarMotoristaAprovado();
        $proposta = $this->propostas->darLance($motorista->usuario_id, $ordem->id, 4200);

        $resultado = $this->propostas->aceitarProposta($this->usuarioId, $ordem->id, $proposta->id);

        $this->assertSame('aceita', $resultado['ordem_frete']->status);
        $this->assertSame($motorista->id, $resultado['ordem_frete']->motorista_id);
        $this->assertEqualsWithDelta(4200.0, (float) $resultado['ordem_frete']->valor_frete, 0.01);
        $this->assertNotNull($resultado['ordem_frete']->aceita_em);
    }

    public function test_aceitar_proposta_recusa_as_demais_propostas_pendentes(): void
    {
        $ordem = $this->ordens->solicitar($this->usuarioId, $this->fazendaId, 'ordem-varias-propostas')['ordem_frete'];
        $m1 = $this->criarMotoristaAprovado();
        $m2 = $this->criarMotoristaAprovado();
        $p1 = $this->propostas->darLance($m1->usuario_id, $ordem->id, 4000);
        $p2 = $this->propostas->darLance($m2->usuario_id, $ordem->id, 3800);

        $this->propostas->aceitarProposta($this->usuarioId, $ordem->id, $p1->id);

        $this->assertSame('recusada', $p2->fresh()->status);
    }

    public function test_aceitar_proposta_cria_obrigacao_financeira_e_comissao(): void
    {
        $ordem = $this->ordens->solicitar($this->usuarioId, $this->fazendaId, 'ordem-financeiro')['ordem_frete'];
        $motorista = $this->criarMotoristaAprovado();
        $proposta = $this->propostas->darLance($motorista->usuario_id, $ordem->id, 5000);

        $resultado = $this->propostas->aceitarProposta($this->usuarioId, $ordem->id, $proposta->id);

        $obrigacao = ObrigacaoFinanceira::where('ordem_frete_id', $resultado['ordem_frete']->id)->first();
        $this->assertNotNull($obrigacao);
        $this->assertSame('a_pagar', $obrigacao->direcao);
        $this->assertEqualsWithDelta(5000.0, (float) $obrigacao->valor, 0.01);

        $comissao = ComissaoPlataforma::where('ordem_frete_id', $resultado['ordem_frete']->id)->first();
        $this->assertNotNull($comissao);
        $this->assertEqualsWithDelta(500.0, (float) $comissao->valor_comissao, 0.01, '10% de 5000');
        $this->assertEqualsWithDelta(10.0, (float) $comissao->percentual_aplicado, 0.01);

        $evento = EventoDominio::where('tipo', 'ordem_frete_aceita')->where('fazenda_id', $this->fazendaId)->first();
        $this->assertNotNull($evento);
    }

    /** INV-043 — editar a comissão global não recalcula uma ComissaoPlataforma já congelada. */
    public function test_editar_comissao_global_nao_afeta_ordem_ja_aceita_inv043(): void
    {
        $ordem = $this->ordens->solicitar($this->usuarioId, $this->fazendaId, 'ordem-congelada')['ordem_frete'];
        $motorista = $this->criarMotoristaAprovado();
        $proposta = $this->propostas->darLance($motorista->usuario_id, $ordem->id, 1000);
        $resultado = $this->propostas->aceitarProposta($this->usuarioId, $ordem->id, $proposta->id);

        $this->config->definirComissaoFrete($this->administradorId, 25); // muda de 10% pra 25%

        $comissao = ComissaoPlataforma::where('ordem_frete_id', $resultado['ordem_frete']->id)->first();
        $this->assertEqualsWithDelta(10.0, (float) $comissao->percentual_aplicado, 0.01, 'percentual continua congelado em 10, nunca recalculado pra 25');
    }

    // ── OrdemFreteService — contratação direta (LAB-FA-027) ─────────────

    public function test_contratar_direto_exige_motorista_aprovado_inv042(): void
    {
        $usuarioMotorista = Usuario::create(['nome' => 'Pendente']);
        $motorista = $this->motoristas->cadastrar($usuarioMotorista->id, '333');

        $this->expectException(DomainException::class);
        $this->ordens->contratarDireto($this->usuarioId, $this->fazendaId, $motorista->id, 5000, 'direto-1');
    }

    public function test_contratar_direto_cria_ordem_ja_aceita(): void
    {
        $motorista = $this->criarMotoristaAprovado();

        $resultado = $this->ordens->contratarDireto($this->usuarioId, $this->fazendaId, $motorista->id, 5000, 'direto-2');

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertSame('aceita', $resultado['ordem_frete']->status);
        $this->assertSame($motorista->id, $resultado['ordem_frete']->motorista_id);
        $this->assertNotNull(ObrigacaoFinanceira::where('ordem_frete_id', $resultado['ordem_frete']->id)->first());
        $this->assertNotNull(ComissaoPlataforma::where('ordem_frete_id', $resultado['ordem_frete']->id)->first());
    }

    public function test_contratar_direto_e_idempotente_por_chave(): void
    {
        $motorista = $this->criarMotoristaAprovado();

        $r1 = $this->ordens->contratarDireto($this->usuarioId, $this->fazendaId, $motorista->id, 5000, 'direto-dup');
        $r2 = $this->ordens->contratarDireto($this->usuarioId, $this->fazendaId, $motorista->id, 5000, 'direto-dup');

        $this->assertTrue($r2['reenvio_detectado']);
        $this->assertSame(1, ObrigacaoFinanceira::where('ordem_frete_id', $r1['ordem_frete']->id)->count());
    }

    public function test_contratar_direto_valor_nao_positivo_e_recusado(): void
    {
        $motorista = $this->criarMotoristaAprovado();

        $this->expectException(DomainException::class);
        $this->ordens->contratarDireto($this->usuarioId, $this->fazendaId, $motorista->id, 0, 'direto-3');
    }

    // ── OrdemFreteService — cancelamento (INV-044) e conclusão ──────────

    public function test_cancelar_ordem_aguardando_lance_funciona(): void
    {
        $ordem = $this->ordens->solicitar($this->usuarioId, $this->fazendaId, 'ordem-cancelar')['ordem_frete'];

        $resultado = $this->ordens->cancelar($this->usuarioId, $ordem->id);

        $this->assertSame('cancelada', $resultado['ordem_frete']->status);
        $this->assertNotNull($resultado['ordem_frete']->cancelada_em);
    }

    public function test_cancelar_recusa_propostas_pendentes(): void
    {
        $ordem = $this->ordens->solicitar($this->usuarioId, $this->fazendaId, 'ordem-cancelar-propostas')['ordem_frete'];
        $motorista = $this->criarMotoristaAprovado();
        $proposta = $this->propostas->darLance($motorista->usuario_id, $ordem->id, 3000);

        $this->ordens->cancelar($this->usuarioId, $ordem->id);

        $this->assertSame('recusada', $proposta->fresh()->status);
    }

    public function test_cancelar_depois_do_aceite_e_recusado_inv044(): void
    {
        $motorista = $this->criarMotoristaAprovado();
        $ordem = $this->ordens->contratarDireto($this->usuarioId, $this->fazendaId, $motorista->id, 5000, 'ordem-nao-cancela')['ordem_frete'];

        $this->expectException(DomainException::class);
        $this->ordens->cancelar($this->usuarioId, $ordem->id);
    }

    public function test_concluir_exige_status_aceita(): void
    {
        $ordem = $this->ordens->solicitar($this->usuarioId, $this->fazendaId, 'ordem-nao-concluida')['ordem_frete'];

        $this->expectException(DomainException::class);
        $this->ordens->concluir($this->usuarioId, $ordem->id);
    }

    public function test_concluir_a_partir_de_aceita_funciona(): void
    {
        $motorista = $this->criarMotoristaAprovado();
        $ordem = $this->ordens->contratarDireto($this->usuarioId, $this->fazendaId, $motorista->id, 5000, 'ordem-concluir')['ordem_frete'];

        $resultado = $this->ordens->concluir($this->usuarioId, $ordem->id);

        $this->assertSame('concluida', $resultado['ordem_frete']->status);
        $this->assertNotNull($resultado['ordem_frete']->concluida_em);
    }

    // ── Motorista com vínculo de Fazenda opcional ───────────────────────

    public function test_motorista_com_vinculo_de_fazenda_pode_ser_contratado_por_outra_fazenda(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Outra Fazenda']);
        $motorista = $this->criarMotoristaAprovado($outraFazenda->id); // vínculo com Fazenda diferente da contratante

        $resultado = $this->ordens->contratarDireto($this->usuarioId, $this->fazendaId, $motorista->id, 5000, 'ordem-vinculo-nao-restringe');

        $this->assertSame('aceita', $resultado['ordem_frete']->status);
    }
}
