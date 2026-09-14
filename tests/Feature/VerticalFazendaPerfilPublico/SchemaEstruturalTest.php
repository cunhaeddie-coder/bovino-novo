<?php

namespace Tests\Feature\VerticalFazendaPerfilPublico;

use App\Models\Fazenda;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer Service — SCHEMA-CONTRATO-FAZENDA-
 * PERFIL-PUBLICO.md. Mesma filosofia dos 23 verticais anteriores: testa
 * que o schema em si (migrations) só permite os estados que o contrato
 * descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_fazenda_aceita_campos_de_perfil_nulos_por_padrao(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);

        $this->assertNull($fazenda->fresh()->descricao);
        $this->assertNull($fazenda->fresh()->logo_url);
        $this->assertNull($fazenda->fresh()->website);
        $this->assertNull($fazenda->fresh()->raca_principal);
        $this->assertNull($fazenda->fresh()->slug);
    }

    public function test_fazenda_nasce_com_ativo_false_por_padrao(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);

        $this->assertFalse($fazenda->fresh()->ativo);
    }

    public function test_fazenda_aceita_perfil_completo_e_ativo_true(): void
    {
        $fazenda = Fazenda::create([
            'nome' => 'A', 'descricao' => 'Uma fazenda', 'logo_url' => 'https://x/logo.png',
            'website' => 'https://x.com', 'raca_principal' => 'Nelore', 'slug' => 'fazenda-a', 'ativo' => true,
        ]);

        $this->assertTrue($fazenda->fresh()->ativo);
        $this->assertSame('fazenda-a', $fazenda->fresh()->slug);
    }

    public function test_fazenda_slug_e_unico(): void
    {
        Fazenda::create(['nome' => 'A', 'slug' => 'fazenda-a']);

        $this->expectException(QueryException::class);
        Fazenda::create(['nome' => 'B', 'slug' => 'fazenda-a']);
    }

    public function test_multiplas_fazendas_aceitam_slug_nulo_simultaneamente(): void
    {
        $f1 = Fazenda::create(['nome' => 'A']);
        $f2 = Fazenda::create(['nome' => 'B']);

        $this->assertNull($f1->fresh()->slug);
        $this->assertNull($f2->fresh()->slug);
    }
}
