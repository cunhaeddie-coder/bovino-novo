<?php

namespace Tests\Feature\VerticalSuporteSugestoesAdmin;

use App\Models\ConversaSuporte;
use App\Models\Fazenda;
use App\Models\Notificacao;
use App\Models\Sugestao;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer Service — SCHEMA-CONTRATO-SUPORTE-
 * SUGESTOES-ADMIN.md. Mesma filosofia dos 29 verticais anteriores: testa
 * que o schema em si (migrations) só permite os estados que o contrato
 * descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    private function criarFazendaEUsuario(): array
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $usuario = Usuario::create(['nome' => 'José']);

        return [$fazenda->id, $usuario->id];
    }

    public function test_sugestao_aceita_criacao_valida(): void
    {
        [$fazendaId, $usuarioId] = $this->criarFazendaEUsuario();

        $sugestao = Sugestao::create(['fazenda_id' => $fazendaId, 'usuario_id' => $usuarioId, 'mensagem' => 'x', 'chave_idempotencia' => 'chave-1']);

        $this->assertNotNull($sugestao->fresh());
        $this->assertNull($sugestao->resposta);
    }

    public function test_sugestao_chave_idempotencia_e_unica_por_fazenda(): void
    {
        [$fazendaId, $usuarioId] = $this->criarFazendaEUsuario();
        Sugestao::create(['fazenda_id' => $fazendaId, 'usuario_id' => $usuarioId, 'mensagem' => 'x', 'chave_idempotencia' => 'chave-1']);

        $this->expectException(QueryException::class);
        Sugestao::create(['fazenda_id' => $fazendaId, 'usuario_id' => $usuarioId, 'mensagem' => 'y', 'chave_idempotencia' => 'chave-1']);
    }

    /** INV-065 — resposta é terminal. */
    public function test_sugestao_respondida_nunca_aceita_novo_update_de_resposta(): void
    {
        [$fazendaId, $usuarioId] = $this->criarFazendaEUsuario();
        $sugestao = Sugestao::create(['fazenda_id' => $fazendaId, 'usuario_id' => $usuarioId, 'mensagem' => 'x', 'resposta' => 'ok', 'respondida_em' => now(), 'chave_idempotencia' => 'chave-1']);

        $this->expectException(LogicException::class);
        $sugestao->update(['resposta' => 'outra coisa']);
    }

    public function test_conversa_suporte_aceita_criacao_valida(): void
    {
        [$fazendaId, $usuarioId] = $this->criarFazendaEUsuario();

        $conversa = ConversaSuporte::create(['fazenda_id' => $fazendaId, 'usuario_id' => $usuarioId, 'mensagem' => 'x', 'chave_idempotencia' => 'chave-1']);

        $this->assertNotNull($conversa->fresh());
    }

    /** INV-065 — mesmo guard de Sugestao. */
    public function test_conversa_suporte_respondida_nunca_aceita_novo_update_de_resposta(): void
    {
        [$fazendaId, $usuarioId] = $this->criarFazendaEUsuario();
        $conversa = ConversaSuporte::create(['fazenda_id' => $fazendaId, 'usuario_id' => $usuarioId, 'mensagem' => 'x', 'resposta' => 'ok', 'respondida_em' => now(), 'chave_idempotencia' => 'chave-1']);

        $this->expectException(LogicException::class);
        $conversa->update(['resposta' => 'outra coisa']);
    }

    public function test_notificacao_para_administrador_aceita_usuario_id_nulo(): void
    {
        [$fazendaId, $usuarioId] = $this->criarFazendaEUsuario();
        $sugestao = Sugestao::create(['fazenda_id' => $fazendaId, 'usuario_id' => $usuarioId, 'mensagem' => 'x', 'chave_idempotencia' => 'chave-1']);

        $notificacao = Notificacao::create(['usuario_id' => null, 'para_administrador' => true, 'tipo' => 'sugestao_criada', 'mensagem' => 'x', 'sugestao_id' => $sugestao->id]);

        $this->assertNotNull($notificacao->fresh());
    }

    /** INV-066 — para_administrador=true nunca pode ter usuario_id preenchido. */
    public function test_notificacao_para_administrador_com_usuario_id_e_recusada(): void
    {
        [$fazendaId, $usuarioId] = $this->criarFazendaEUsuario();
        $sugestao = Sugestao::create(['fazenda_id' => $fazendaId, 'usuario_id' => $usuarioId, 'mensagem' => 'x', 'chave_idempotencia' => 'chave-1']);

        $this->expectException(LogicException::class);
        Notificacao::create(['usuario_id' => $usuarioId, 'para_administrador' => true, 'tipo' => 'sugestao_criada', 'mensagem' => 'x', 'sugestao_id' => $sugestao->id]);
    }

    public function test_notificacao_sem_para_administrador_exige_usuario_id(): void
    {
        [$fazendaId, $usuarioId] = $this->criarFazendaEUsuario();
        $sugestao = Sugestao::create(['fazenda_id' => $fazendaId, 'usuario_id' => $usuarioId, 'mensagem' => 'x', 'chave_idempotencia' => 'chave-1']);

        $this->expectException(LogicException::class);
        Notificacao::create(['usuario_id' => null, 'para_administrador' => false, 'tipo' => 'sugestao_respondida', 'mensagem' => 'x', 'sugestao_id' => $sugestao->id]);
    }

    public function test_notificacao_exige_exatamente_um_entre_sugestao_e_conversa_suporte(): void
    {
        $this->expectException(LogicException::class);
        Notificacao::create(['usuario_id' => null, 'para_administrador' => true, 'tipo' => 'sugestao_criada', 'mensagem' => 'x']);
    }

    /** Idempotência sob reprocessamento — UNIQUE(sugestao_id, tipo). */
    public function test_notificacao_e_unica_por_sugestao_e_tipo(): void
    {
        [$fazendaId, $usuarioId] = $this->criarFazendaEUsuario();
        $sugestao = Sugestao::create(['fazenda_id' => $fazendaId, 'usuario_id' => $usuarioId, 'mensagem' => 'x', 'chave_idempotencia' => 'chave-1']);
        Notificacao::create(['usuario_id' => null, 'para_administrador' => true, 'tipo' => 'sugestao_criada', 'mensagem' => 'x', 'sugestao_id' => $sugestao->id]);

        $this->expectException(QueryException::class);
        Notificacao::create(['usuario_id' => null, 'para_administrador' => true, 'tipo' => 'sugestao_criada', 'mensagem' => 'x', 'sugestao_id' => $sugestao->id]);
    }
}
