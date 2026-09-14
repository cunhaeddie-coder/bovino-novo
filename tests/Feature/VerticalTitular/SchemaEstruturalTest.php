<?php

namespace Tests\Feature\VerticalTitular;

use App\Models\Fazenda;
use App\Models\Titular;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer Service — SCHEMA-CONTRATO-TITULAR.md.
 * Mesma filosofia dos 24 verticais anteriores: testa que o schema em si
 * (migrations) só permite os estados que o contrato descreve — nunca chama
 * um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_fazenda_aceita_titular_id_nulo_por_padrao(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);

        $this->assertNull($fazenda->fresh()->titular_id);
    }

    public function test_fazenda_aceita_titular_vinculado(): void
    {
        $titular = Titular::create(['documento' => '11144477735', 'tipo_documento' => 'cpf']);
        $fazenda = Fazenda::create(['nome' => 'A', 'titular_id' => $titular->id]);

        $this->assertSame($titular->id, $fazenda->fresh()->titular_id);
    }

    public function test_titular_documento_e_unico(): void
    {
        Titular::create(['documento' => '11144477735', 'tipo_documento' => 'cpf']);

        $this->expectException(QueryException::class);
        Titular::create(['documento' => '11144477735', 'tipo_documento' => 'cnpj']);
    }

    public function test_duas_fazendas_podem_compartilhar_o_mesmo_titular(): void
    {
        $titular = Titular::create(['documento' => '11222333000181', 'tipo_documento' => 'cnpj']);
        $f1 = Fazenda::create(['nome' => 'A', 'titular_id' => $titular->id]);
        $f2 = Fazenda::create(['nome' => 'B', 'titular_id' => $titular->id]);

        $this->assertSame(2, $titular->fazendas()->count());
        $this->assertSame($f1->titular_id, $f2->titular_id);
    }

    public function test_fazenda_pode_trocar_de_titular(): void
    {
        $titular1 = Titular::create(['documento' => '11144477735', 'tipo_documento' => 'cpf']);
        $titular2 = Titular::create(['documento' => '11222333000181', 'tipo_documento' => 'cnpj']);
        $fazenda = Fazenda::create(['nome' => 'A', 'titular_id' => $titular1->id]);

        $fazenda->update(['titular_id' => $titular2->id]);

        $this->assertSame($titular2->id, $fazenda->fresh()->titular_id);
    }
}
