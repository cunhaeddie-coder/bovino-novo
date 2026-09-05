<?php

namespace Tests\Feature\VerticalMarketplace;

use App\Models\Animal;
use App\Models\Anuncio;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Negociacao;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer NegociacaoService —
 * SCHEMA-CONTRATO-MARKETPLACE.md. Mesma filosofia dos 4 verticais
 * anteriores: testa que o schema em si (migrations + guards de Model) só
 * permite os estados que o contrato descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    private function anuncio(int $fazendaId, float $precoTotal = 1000): Anuncio
    {
        return Anuncio::create([
            'fazenda_id' => $fazendaId, 'preco_total' => $precoTotal,
            'status' => 'ativo', 'publicado_em' => '2026-01-01',
        ]);
    }

    public function test_negociacao_so_pode_ser_concluida_com_venda_id_e_compra_id_inv034(): void
    {
        $vendedora = Fazenda::create(['nome' => 'Vendedora']);
        $compradora = Fazenda::create(['nome' => 'Compradora']);
        $anuncio = $this->anuncio($vendedora->id);

        $this->assertThrows(
            fn () => Negociacao::create([
                'anuncio_id' => $anuncio->id, 'fazenda_compradora_id' => $compradora->id,
                'preco_proposto' => 1000, 'status' => 'concluida', 'chave_idempotencia' => 'x',
            ]),
            LogicException::class
        );

        // Só venda_id, sem compra_id — ainda recusado (INV-034 exige os dois).
        $this->assertThrows(
            fn () => Negociacao::create([
                'anuncio_id' => $anuncio->id, 'fazenda_compradora_id' => $compradora->id,
                'preco_proposto' => 1000, 'status' => 'concluida', 'chave_idempotencia' => 'y',
                'venda_id' => null, 'compra_id' => null,
            ]),
            LogicException::class
        );
    }

    public function test_negociacao_concluida_cancelada_ou_recusada_e_terminal(): void
    {
        $vendedora = Fazenda::create(['nome' => 'Vendedora']);
        $compradora = Fazenda::create(['nome' => 'Compradora']);
        $anuncio = $this->anuncio($vendedora->id);

        $negociacao = Negociacao::create([
            'anuncio_id' => $anuncio->id, 'fazenda_compradora_id' => $compradora->id,
            'preco_proposto' => 1000, 'status' => 'cancelada', 'chave_idempotencia' => 'x',
        ]);

        $this->assertThrows(
            fn () => $negociacao->update(['preco_proposto' => 999]),
            LogicException::class
        );
    }

    public function test_negociacao_em_status_proposta_aceita_edicao_normal(): void
    {
        $vendedora = Fazenda::create(['nome' => 'Vendedora']);
        $compradora = Fazenda::create(['nome' => 'Compradora']);
        $anuncio = $this->anuncio($vendedora->id);

        $negociacao = Negociacao::create([
            'anuncio_id' => $anuncio->id, 'fazenda_compradora_id' => $compradora->id,
            'preco_proposto' => 1000, 'status' => 'proposta', 'chave_idempotencia' => 'x',
        ]);

        $negociacao->update(['preco_proposto' => 900, 'status' => 'aceita']);
        $this->assertSame('aceita', $negociacao->fresh()->status);
    }

    public function test_anuncio_animal_e_unico_por_par(): void
    {
        $vendedora = Fazenda::create(['nome' => 'Vendedora']);
        $anuncio = $this->anuncio($vendedora->id);
        $animal = Animal::create(['fazenda_id' => $vendedora->id, 'lote_id' => null, 'custo_aquisicao' => 100, 'status' => 'ativo']);

        $anuncio->animais()->attach($animal->id);

        $this->assertThrows(
            fn () => $anuncio->animais()->attach($animal->id),
            QueryException::class
        );
    }

    public function test_negociacao_e_unica_por_fazenda_compradora_e_chave_idempotencia(): void
    {
        $vendedora = Fazenda::create(['nome' => 'Vendedora']);
        $compradora = Fazenda::create(['nome' => 'Compradora']);
        $anuncio = $this->anuncio($vendedora->id);

        Negociacao::create([
            'anuncio_id' => $anuncio->id, 'fazenda_compradora_id' => $compradora->id,
            'preco_proposto' => 1000, 'status' => 'proposta', 'chave_idempotencia' => 'mesma-chave',
        ]);

        $this->assertThrows(
            fn () => Negociacao::create([
                'anuncio_id' => $anuncio->id, 'fazenda_compradora_id' => $compradora->id,
                'preco_proposto' => 500, 'status' => 'proposta', 'chave_idempotencia' => 'mesma-chave',
            ]),
            QueryException::class
        );
    }

    public function test_fornecedor_fazenda_id_e_nullable_e_unico_quando_preenchido(): void
    {
        $externo = Fornecedor::create(['nome' => 'Fornecedor Externo']);
        $this->assertNull($externo->fazenda_id);

        $vendedora = Fazenda::create(['nome' => 'Vendedora']);
        $viaFazenda = Fornecedor::create(['nome' => 'Vendedora', 'fazenda_id' => $vendedora->id]);
        $this->assertSame($vendedora->id, $viaFazenda->fazenda_id);

        $this->assertThrows(
            fn () => Fornecedor::create(['nome' => 'Duplicado', 'fazenda_id' => $vendedora->id]),
            QueryException::class
        );
    }
}
