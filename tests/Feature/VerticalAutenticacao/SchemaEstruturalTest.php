<?php

namespace Tests\Feature\VerticalAutenticacao;

use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer Service — SCHEMA-CONTRATO-
 * AUTENTICACAO.md. Mesma filosofia dos 32 verticais anteriores: testa que
 * o schema em si (migrations) só permite os estados que o contrato
 * descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_de_dominio_puro_sem_credenciais_continua_valido(): void
    {
        $usuario = Usuario::create(['nome' => 'José']);

        $this->assertNotNull($usuario->fresh());
        $this->assertNull($usuario->email);
        $this->assertNull($usuario->celular);
        $this->assertNull($usuario->senha);
    }

    public function test_usuario_com_email_e_senha_e_valido(): void
    {
        $usuario = Usuario::create(['nome' => 'José', 'email' => 'jose@fazenda.com', 'senha' => 'hash']);

        $this->assertSame('jose@fazenda.com', $usuario->fresh()->email);
    }

    public function test_usuario_com_celular_e_senha_e_valido(): void
    {
        $usuario = Usuario::create(['nome' => 'José', 'celular' => '69999999999', 'senha' => 'hash']);

        $this->assertSame('69999999999', $usuario->fresh()->celular);
    }

    /** SCHEMA-CONTRATO-AUTENTICACAO.md §2 — senha exige pelo menos um entre email/celular. */
    public function test_usuario_com_senha_sem_email_nem_celular_e_recusado(): void
    {
        $this->expectException(LogicException::class);
        Usuario::create(['nome' => 'José', 'senha' => 'hash']);
    }

    public function test_email_e_unico(): void
    {
        Usuario::create(['nome' => 'José', 'email' => 'jose@fazenda.com', 'senha' => 'hash']);

        $this->expectException(QueryException::class);
        Usuario::create(['nome' => 'Outro', 'email' => 'jose@fazenda.com', 'senha' => 'hash']);
    }

    public function test_celular_e_unico(): void
    {
        Usuario::create(['nome' => 'José', 'celular' => '69999999999', 'senha' => 'hash']);

        $this->expectException(QueryException::class);
        Usuario::create(['nome' => 'Outro', 'celular' => '69999999999', 'senha' => 'hash']);
    }

    /** senha nunca aparece em serialização — SCHEMA-CONTRATO-AUTENTICACAO.md §1. */
    public function test_senha_fica_oculta_na_serializacao(): void
    {
        $usuario = Usuario::create(['nome' => 'José', 'email' => 'jose@fazenda.com', 'senha' => 'hash']);

        $this->assertArrayNotHasKey('senha', $usuario->toArray());
    }
}
