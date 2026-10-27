<?php

namespace Tests\Feature\Operacao;

use App\Models\Fazenda;
use App\Models\Papel;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * fazenda:criar — o cadastro só cria o Usuario; sem este comando, a
 * homologação não tem como dar uma Fazenda a ninguém.
 */
class CriarFazendaComandoTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_fazenda_e_liga_o_usuario_como_dono(): void
    {
        $usuario = app(AuthService::class)->registrar('José', 'jose@fazenda.com', null, 'senha123');

        $this->artisan('fazenda:criar', ['identificador' => 'jose@fazenda.com', 'nome' => 'Fazenda Alegria', '--estado' => 'mt'])
            ->expectsOutputToContain('"Fazenda Alegria" criada; José é dono.')
            ->assertSuccessful();

        $fazenda = Fazenda::sole();
        $this->assertSame('Fazenda Alegria', $fazenda->nome);
        $this->assertSame('MT', $fazenda->estado);
        $this->assertFalse((bool) $fazenda->ativo, 'Fazenda nova nasce despublicada');
        $this->assertTrue($usuario->temRelacaoComFazenda($fazenda->id));
        $this->assertSame('dono', Papel::sole()->papel);
    }

    public function test_acha_o_usuario_pelo_celular(): void
    {
        $usuario = app(AuthService::class)->registrar('Maria', null, '65999990000', 'senha123');

        $this->artisan('fazenda:criar', ['identificador' => '65999990000', 'nome' => 'Sítio Boa Vista'])
            ->assertSuccessful();

        $this->assertTrue($usuario->temRelacaoComFazenda(Fazenda::sole()->id));
        $this->assertNull(Fazenda::sole()->estado);
    }

    /** Prova de ponta a ponta: depois do comando, o login já devolve a Fazenda pro frontend. */
    public function test_login_depois_do_comando_traz_a_fazenda(): void
    {
        app(AuthService::class)->registrar('José', 'jose@fazenda.com', null, 'senha123');
        $this->artisan('fazenda:criar', ['identificador' => 'jose@fazenda.com', 'nome' => 'Fazenda Alegria']);

        $this->postJson('/api/auth/login', ['identificador' => 'jose@fazenda.com', 'senha' => 'senha123'])
            ->assertStatus(200)
            ->assertJsonPath('usuario.papeis.0.fazenda.nome', 'Fazenda Alegria');
    }

    public function test_usuario_inexistente_falha_sem_criar_nada(): void
    {
        $this->artisan('fazenda:criar', ['identificador' => 'ninguem@fazenda.com', 'nome' => 'Fazenda Fantasma'])
            ->expectsOutputToContain('Nenhum usuário com o identificador ninguem@fazenda.com')
            ->assertFailed();

        $this->assertSame(0, Fazenda::count());
    }

    public function test_nome_vazio_e_estado_invalido_sao_recusados(): void
    {
        app(AuthService::class)->registrar('José', 'jose@fazenda.com', null, 'senha123');

        $this->artisan('fazenda:criar', ['identificador' => 'jose@fazenda.com', 'nome' => '   '])->assertFailed();
        $this->artisan('fazenda:criar', ['identificador' => 'jose@fazenda.com', 'nome' => 'Fazenda Alegria', '--estado' => 'Mato Grosso'])->assertFailed();

        $this->assertSame(0, Fazenda::count());
        $this->assertSame(0, Papel::count());
    }

    public function test_segunda_fazenda_do_mesmo_usuario_avisa(): void
    {
        app(AuthService::class)->registrar('José', 'jose@fazenda.com', null, 'senha123');
        $this->artisan('fazenda:criar', ['identificador' => 'jose@fazenda.com', 'nome' => 'Fazenda Alegria']);

        $this->artisan('fazenda:criar', ['identificador' => 'jose@fazenda.com', 'nome' => 'Fazenda Esperança'])
            ->expectsOutputToContain('já tinha 1 outra(s) Fazenda(s)')
            ->assertSuccessful();

        $this->assertSame(2, Papel::count());
    }
}
