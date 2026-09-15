<?php

namespace Tests\Feature\VerticalSuporteSugestoesAdmin;

use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Notificacao;
use App\Models\Papel;
use App\Models\Sugestao;
use App\Models\Usuario;
use App\Services\ConversaSuporteService;
use App\Services\OutboxService;
use App\Services\SugestaoService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 30 (Suporte/Sugestões/Admin) — nasce de
 * VERTICAL-SUPORTE-SUGESTOES-ADMIN.md e SCHEMA-CONTRATO-SUPORTE-
 * SUGESTOES-ADMIN.md. Formaliza LAB-SA-025/026: Sugestão/Conversa de
 * Suporte funcionam, mas nunca notificavam ninguém — primeiro vertical a
 * implementar INV-015 de fato.
 */
class SuporteSugestoesAdminDominioTest extends TestCase
{
    use RefreshDatabase;

    private SugestaoService $sugestaoService;

    private ConversaSuporteService $conversaSuporteService;

    private int $fazendaId;

    private int $usuarioId;

    private int $administradorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sugestaoService = app(SugestaoService::class);
        $this->conversaSuporteService = app(ConversaSuporteService::class);

        $this->fazendaId = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->usuarioId = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->usuarioId, 'fazenda_id' => $this->fazendaId, 'papel' => 'dono']);
        $this->administradorId = Usuario::create(['nome' => 'Admin', 'eh_administrador' => true])->id;
    }

    // ── SugestaoService ─────────────────────────────────────────────────

    public function test_criar_sugestao(): void
    {
        $resultado = $this->sugestaoService->criar($this->usuarioId, $this->fazendaId, 'Melhorar o relatório', 'chave-1');

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertNull($resultado['sugestao']->resposta);
    }

    public function test_criar_sugestao_reenvio_nao_duplica(): void
    {
        $r1 = $this->sugestaoService->criar($this->usuarioId, $this->fazendaId, 'x', 'chave-1');
        $r2 = $this->sugestaoService->criar($this->usuarioId, $this->fazendaId, 'x', 'chave-1');

        $this->assertTrue($r2['reenvio_detectado']);
        $this->assertSame($r1['sugestao']->id, $r2['sugestao']->id);
        $this->assertSame(1, Sugestao::count());
    }

    public function test_criar_sugestao_sem_relacao_com_fazenda_e_recusado(): void
    {
        $outroUsuarioId = Usuario::create(['nome' => 'Maria'])->id;

        $this->expectException(DomainException::class);
        $this->sugestaoService->criar($outroUsuarioId, $this->fazendaId, 'x', 'chave-1');
    }

    public function test_responder_sugestao(): void
    {
        $sugestao = $this->sugestaoService->criar($this->usuarioId, $this->fazendaId, 'x', 'chave-1')['sugestao'];

        $respondida = $this->sugestaoService->responder($this->administradorId, $sugestao->id, 'Vamos implementar!');

        $this->assertSame('Vamos implementar!', $respondida->resposta);
        $this->assertNotNull($respondida->respondida_em);
    }

    public function test_responder_sugestao_sem_ser_administrador_e_recusado(): void
    {
        $sugestao = $this->sugestaoService->criar($this->usuarioId, $this->fazendaId, 'x', 'chave-1')['sugestao'];

        $this->expectException(DomainException::class);
        $this->sugestaoService->responder($this->usuarioId, $sugestao->id, 'x');
    }

    /** INV-065 — responder de novo é idempotente, nunca sobrescreve. */
    public function test_responder_sugestao_ja_respondida_nao_reprocessa(): void
    {
        $sugestao = $this->sugestaoService->criar($this->usuarioId, $this->fazendaId, 'x', 'chave-1')['sugestao'];
        $this->sugestaoService->responder($this->administradorId, $sugestao->id, 'primeira resposta');

        $segunda = $this->sugestaoService->responder($this->administradorId, $sugestao->id, 'segunda resposta');

        $this->assertSame('primeira resposta', $segunda->resposta);
    }

    // ── ConversaSuporteService ──────────────────────────────────────────

    public function test_abrir_e_responder_conversa_suporte(): void
    {
        $conversa = $this->conversaSuporteService->abrir($this->usuarioId, $this->fazendaId, 'Preciso de ajuda', 'chave-1')['conversa'];

        $respondida = $this->conversaSuporteService->responder($this->administradorId, $conversa->id, 'Como posso ajudar?');

        $this->assertSame('Como posso ajudar?', $respondida->resposta);
    }

    // ── Notificação (consumidor de outbox — INV-015 de fato) ───────────

    public function test_criar_sugestao_gera_notificacao_para_administrador(): void
    {
        $sugestao = $this->sugestaoService->criar($this->usuarioId, $this->fazendaId, 'x', 'chave-1')['sugestao'];

        app(OutboxService::class)->processarPendentes();

        $notificacao = Notificacao::where('sugestao_id', $sugestao->id)->where('tipo', 'sugestao_criada')->first();
        $this->assertNotNull($notificacao, 'nenhuma notificação nasceu pro administrador');
        $this->assertTrue($notificacao->para_administrador);
        $this->assertNull($notificacao->usuario_id);
    }

    public function test_responder_sugestao_gera_notificacao_para_o_produtor(): void
    {
        $sugestao = $this->sugestaoService->criar($this->usuarioId, $this->fazendaId, 'x', 'chave-1')['sugestao'];
        $this->sugestaoService->responder($this->administradorId, $sugestao->id, 'ok');

        app(OutboxService::class)->processarPendentes();

        $notificacao = Notificacao::where('sugestao_id', $sugestao->id)->where('tipo', 'sugestao_respondida')->first();
        $this->assertNotNull($notificacao, 'o produtor nunca foi avisado da resposta');
        $this->assertFalse($notificacao->para_administrador);
        $this->assertSame($this->usuarioId, $notificacao->usuario_id);
    }

    public function test_abrir_conversa_suporte_gera_notificacao_para_administrador(): void
    {
        $conversa = $this->conversaSuporteService->abrir($this->usuarioId, $this->fazendaId, 'x', 'chave-1')['conversa'];

        app(OutboxService::class)->processarPendentes();

        $notificacao = Notificacao::where('conversa_suporte_id', $conversa->id)->where('tipo', 'conversa_suporte_aberta')->first();
        $this->assertNotNull($notificacao);
        $this->assertTrue($notificacao->para_administrador);
    }

    public function test_responder_conversa_suporte_gera_notificacao_para_o_produtor(): void
    {
        $conversa = $this->conversaSuporteService->abrir($this->usuarioId, $this->fazendaId, 'x', 'chave-1')['conversa'];
        $this->conversaSuporteService->responder($this->administradorId, $conversa->id, 'ok');

        app(OutboxService::class)->processarPendentes();

        $notificacao = Notificacao::where('conversa_suporte_id', $conversa->id)->where('tipo', 'conversa_suporte_respondida')->first();
        $this->assertNotNull($notificacao);
        $this->assertSame($this->usuarioId, $notificacao->usuario_id);
    }

    public function test_reprocessar_o_mesmo_evento_nunca_duplica_a_notificacao(): void
    {
        $sugestao = $this->sugestaoService->criar($this->usuarioId, $this->fazendaId, 'x', 'chave-1')['sugestao'];

        $evento = EventoDominio::where('tipo', 'sugestao_criada')->firstOrFail();
        app(OutboxService::class)->processar($evento);
        app(OutboxService::class)->processar($evento->fresh());

        $this->assertSame(1, Notificacao::where('sugestao_id', $sugestao->id)->count());
    }
}
