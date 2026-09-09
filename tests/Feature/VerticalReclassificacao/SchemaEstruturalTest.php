<?php

namespace Tests\Feature\VerticalReclassificacao;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\ReclassificacaoCategoria;
use App\Models\ReclassificacaoFinalidade;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer Service — SCHEMA-CONTRATO-RECLASSIFICACAO.md.
 * Mesma filosofia dos 15 verticais anteriores: testa que o schema em si
 * (migrations + guards de Model) só permite os estados que o contrato
 * descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_reclassificacao_categoria_e_imutavel_depois_de_registrada(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $r = ReclassificacaoCategoria::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1, 2], 'categoria_nova' => 'novilho', 'chave_idempotencia' => 'x',
        ]);

        $this->assertThrows(fn () => $r->update(['categoria_nova' => 'boi']), LogicException::class);
    }

    public function test_reclassificacao_finalidade_e_imutavel_depois_de_registrada(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $r = ReclassificacaoFinalidade::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1, 2], 'finalidade_nova' => 'recria', 'chave_idempotencia' => 'x',
        ]);

        $this->assertThrows(fn () => $r->update(['finalidade_nova' => 'engorda']), LogicException::class);
    }

    public function test_reclassificacao_categoria_e_unica_por_fazenda_e_chave_idempotencia(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        ReclassificacaoCategoria::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1, 2], 'categoria_nova' => 'novilho', 'chave_idempotencia' => 'mesma-chave',
        ]);

        $this->assertThrows(
            fn () => ReclassificacaoCategoria::create([
                'fazenda_id' => $fazenda->id, 'animal_ids' => [3], 'categoria_nova' => 'boi', 'chave_idempotencia' => 'mesma-chave',
            ]),
            QueryException::class
        );
    }

    public function test_reclassificacao_finalidade_e_unica_por_fazenda_e_chave_idempotencia(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        ReclassificacaoFinalidade::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1, 2], 'finalidade_nova' => 'recria', 'chave_idempotencia' => 'mesma-chave',
        ]);

        $this->assertThrows(
            fn () => ReclassificacaoFinalidade::create([
                'fazenda_id' => $fazenda->id, 'animal_ids' => [3], 'finalidade_nova' => 'engorda', 'chave_idempotencia' => 'mesma-chave',
            ]),
            QueryException::class
        );
    }

    public function test_animal_nasce_com_categoria_e_finalidade_nulas(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $animal = Animal::create(['fazenda_id' => $fazenda->id, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo']);

        $this->assertNull($animal->categoria);
        $this->assertNull($animal->finalidade);
    }
}
