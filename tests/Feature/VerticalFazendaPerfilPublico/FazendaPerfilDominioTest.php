<?php

namespace Tests\Feature\VerticalFazendaPerfilPublico;

use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\FazendaPerfilService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 24 (Fazenda/Perfil Público) — nasce de
 * VERTICAL-FAZENDA-PERFIL-PUBLICO.md e SCHEMA-CONTRATO-FAZENDA-PERFIL-
 * PUBLICO.md. Reproduz LAB-SA-024 (publicar ≠ criar).
 */
class FazendaPerfilDominioTest extends TestCase
{
    use RefreshDatabase;

    private FazendaPerfilService $service;

    private int $fazendaId;

    private int $usuarioId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FazendaPerfilService::class);
        $this->fazendaId = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->usuarioId = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->usuarioId, 'fazenda_id' => $this->fazendaId, 'papel' => 'dono']);
    }

    public function test_publicar_gera_slug_automaticamente_do_nome(): void
    {
        $fazenda = $this->service->publicar($this->usuarioId, $this->fazendaId);

        $this->assertSame('fazenda-alegria', $fazenda->slug);
    }

    public function test_publicar_ativa_a_fazenda(): void
    {
        $fazenda = $this->service->publicar($this->usuarioId, $this->fazendaId);

        $this->assertTrue($fazenda->ativo);
    }

    public function test_publicar_sem_nenhum_dado_extra_funciona(): void
    {
        // Decisão do produtor — nenhum campo além do nome é obrigatório.
        $fazenda = $this->service->publicar($this->usuarioId, $this->fazendaId, []);

        $this->assertTrue($fazenda->ativo);
        $this->assertNull($fazenda->descricao);
    }

    public function test_publicar_grava_campos_de_perfil_fornecidos(): void
    {
        $fazenda = $this->service->publicar($this->usuarioId, $this->fazendaId, [
            'descricao' => 'A melhor fazenda', 'raca_principal' => 'Nelore',
        ]);

        $this->assertSame('A melhor fazenda', $fazenda->descricao);
        $this->assertSame('Nelore', $fazenda->raca_principal);
    }

    public function test_publicar_nao_apaga_campo_ja_preenchido_quando_nao_fornecido_de_novo(): void
    {
        $this->service->publicar($this->usuarioId, $this->fazendaId, ['descricao' => 'Descrição original']);
        $fazenda = $this->service->publicar($this->usuarioId, $this->fazendaId, ['raca_principal' => 'Angus']);

        $this->assertSame('Descrição original', $fazenda->descricao);
        $this->assertSame('Angus', $fazenda->raca_principal);
    }

    public function test_publicar_nao_regenera_slug_ja_existente(): void
    {
        $primeira = $this->service->publicar($this->usuarioId, $this->fazendaId);
        $this->service->despublicar($this->usuarioId, $this->fazendaId);
        $segunda = $this->service->publicar($this->usuarioId, $this->fazendaId, ['descricao' => 'Nova descrição']);

        $this->assertSame($primeira->slug, $segunda->slug);
    }

    public function test_publicar_com_colisao_de_slug_gera_sufixo_numerico(): void
    {
        $outraFazendaId = Fazenda::create(['nome' => 'Fazenda Alegria'])->id; // mesmo nome
        $outroUsuarioId = Usuario::create(['nome' => 'Maria'])->id;
        Papel::create(['usuario_id' => $outroUsuarioId, 'fazenda_id' => $outraFazendaId, 'papel' => 'dono']);

        $primeira = $this->service->publicar($this->usuarioId, $this->fazendaId);
        $segunda = $this->service->publicar($outroUsuarioId, $outraFazendaId);

        $this->assertSame('fazenda-alegria', $primeira->slug);
        $this->assertSame('fazenda-alegria-2', $segunda->slug);
    }

    public function test_despublicar_desativa_sem_apagar_campos(): void
    {
        $this->service->publicar($this->usuarioId, $this->fazendaId, ['descricao' => 'Descrição']);
        $fazenda = $this->service->despublicar($this->usuarioId, $this->fazendaId);

        $this->assertFalse($fazenda->ativo);
        $this->assertSame('Descrição', $fazenda->descricao);
        $this->assertNotNull($fazenda->slug);
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_pode_publicar(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Outra']);

        $this->expectException(DomainException::class);
        $this->service->publicar($this->usuarioId, $outraFazenda->id);
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_pode_despublicar(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Outra']);

        $this->expectException(DomainException::class);
        $this->service->despublicar($this->usuarioId, $outraFazenda->id);
    }

    // ── Leitura pública (perfilPublico) ─────────────────────────────────

    public function test_perfil_publico_retorna_dados_quando_ativo(): void
    {
        $this->service->publicar($this->usuarioId, $this->fazendaId, ['descricao' => 'Descrição', 'raca_principal' => 'Nelore']);

        $perfil = $this->service->perfilPublico('fazenda-alegria');

        $this->assertNotNull($perfil);
        $this->assertSame('Fazenda Alegria', $perfil['nome']);
        $this->assertSame('Descrição', $perfil['descricao']);
        $this->assertSame('Nelore', $perfil['raca_principal']);
    }

    /** INV-049 — Fazenda despublicada nunca é encontrável por slug. */
    public function test_perfil_publico_retorna_null_quando_despublicada_inv049(): void
    {
        $this->service->publicar($this->usuarioId, $this->fazendaId);
        $this->service->despublicar($this->usuarioId, $this->fazendaId);

        $perfil = $this->service->perfilPublico('fazenda-alegria');

        $this->assertNull($perfil);
    }

    public function test_perfil_publico_retorna_null_quando_slug_inexistente(): void
    {
        $perfil = $this->service->perfilPublico('nao-existe');

        $this->assertNull($perfil);
    }

    public function test_perfil_publico_nunca_expoe_dados_privados(): void
    {
        $this->service->publicar($this->usuarioId, $this->fazendaId);

        $perfil = $this->service->perfilPublico('fazenda-alegria');

        $this->assertArrayNotHasKey('usuarios', $perfil);
        $this->assertArrayNotHasKey('papeis', $perfil);
        $this->assertArrayNotHasKey('id', $perfil);
        $this->assertSame(['nome', 'estado', 'descricao', 'logo_url', 'website', 'raca_principal'], array_keys($perfil));
    }
}
