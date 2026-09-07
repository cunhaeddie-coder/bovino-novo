<?php

namespace Tests\Feature\VerticalFolhaPagamento;

use App\Models\Fazenda;
use App\Models\FolhaPagamento;
use App\Models\Funcionario;
use App\Models\ObrigacaoFinanceira;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer Service — SCHEMA-CONTRATO-FOLHA-PAGAMENTO.md.
 * Mesma filosofia dos 9 verticais anteriores: testa que o schema em si
 * (migrations + guards de Model) só permite os estados que o contrato
 * descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_funcionario_exige_salario_positivo(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);

        $this->assertThrows(
            fn () => Funcionario::create([
                'fazenda_id' => $fazenda->id, 'nome' => 'Eddie', 'salario' => 0,
                'status' => 'ativo', 'data_contratacao' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'x',
            ]),
            LogicException::class
        );
    }

    public function test_funcionario_e_mutavel_salario_pode_ser_editado(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $funcionario = Funcionario::create([
            'fazenda_id' => $fazenda->id, 'nome' => 'Eddie', 'salario' => 3000,
            'status' => 'ativo', 'data_contratacao' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'x',
        ]);

        $funcionario->update(['salario' => 3500]);
        $this->assertEqualsWithDelta(3500.0, (float) $funcionario->fresh()->salario, 0.01);
    }

    public function test_funcionario_e_unico_por_fazenda_e_chave_idempotencia(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        Funcionario::create([
            'fazenda_id' => $fazenda->id, 'nome' => 'Eddie', 'salario' => 3000,
            'status' => 'ativo', 'data_contratacao' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'mesma-chave',
        ]);

        $this->assertThrows(
            fn () => Funcionario::create([
                'fazenda_id' => $fazenda->id, 'nome' => 'Outro', 'salario' => 2000,
                'status' => 'ativo', 'data_contratacao' => '2026-01-02 08:00:00', 'chave_idempotencia' => 'mesma-chave',
            ]),
            QueryException::class
        );
    }

    public function test_folha_pagamento_e_imutavel_depois_de_gerada(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $funcionario = Funcionario::create([
            'fazenda_id' => $fazenda->id, 'nome' => 'Eddie', 'salario' => 3000,
            'status' => 'ativo', 'data_contratacao' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'x',
        ]);
        $folha = FolhaPagamento::create([
            'fazenda_id' => $fazenda->id, 'funcionario_id' => $funcionario->id,
            'mes_referencia' => '2026-01', 'valor' => 3000, 'data_geracao' => '2026-01-31 10:00:00',
        ]);

        $this->assertThrows(
            fn () => $folha->update(['valor' => 3500]),
            LogicException::class
        );
    }

    public function test_folha_pagamento_e_unica_por_funcionario_e_mes(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $funcionario = Funcionario::create([
            'fazenda_id' => $fazenda->id, 'nome' => 'Eddie', 'salario' => 3000,
            'status' => 'ativo', 'data_contratacao' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'x',
        ]);
        FolhaPagamento::create([
            'fazenda_id' => $fazenda->id, 'funcionario_id' => $funcionario->id,
            'mes_referencia' => '2026-01', 'valor' => 3000, 'data_geracao' => '2026-01-31 10:00:00',
        ]);

        $this->assertThrows(
            fn () => FolhaPagamento::create([
                'fazenda_id' => $fazenda->id, 'funcionario_id' => $funcionario->id,
                'mes_referencia' => '2026-01', 'valor' => 3000, 'data_geracao' => '2026-01-31 11:00:00',
            ]),
            QueryException::class
        );
    }

    /** ObrigacaoFinanceira::booted() estendido pro 4º membro — folha_pagamento_id. */
    public function test_obrigacao_financeira_aceita_folha_pagamento_id_como_4o_membro(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $funcionario = Funcionario::create([
            'fazenda_id' => $fazenda->id, 'nome' => 'Eddie', 'salario' => 3000,
            'status' => 'ativo', 'data_contratacao' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'x',
        ]);
        $folha = FolhaPagamento::create([
            'fazenda_id' => $fazenda->id, 'funcionario_id' => $funcionario->id,
            'mes_referencia' => '2026-01', 'valor' => 3000, 'data_geracao' => '2026-01-31 10:00:00',
        ]);

        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda->id, 'folha_pagamento_id' => $folha->id, 'direcao' => 'a_pagar', 'valor' => 3000,
        ]);

        $this->assertSame($folha->id, $obrigacao->folha_pagamento_id);
        $this->assertSame('pendente', $obrigacao->status);
    }

    public function test_obrigacao_financeira_de_folha_pagamento_exige_direcao_a_pagar(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $funcionario = Funcionario::create([
            'fazenda_id' => $fazenda->id, 'nome' => 'Eddie', 'salario' => 3000,
            'status' => 'ativo', 'data_contratacao' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'x',
        ]);
        $folha = FolhaPagamento::create([
            'fazenda_id' => $fazenda->id, 'funcionario_id' => $funcionario->id,
            'mes_referencia' => '2026-01', 'valor' => 3000, 'data_geracao' => '2026-01-31 10:00:00',
        ]);

        $this->assertThrows(
            fn () => ObrigacaoFinanceira::create([
                'fazenda_id' => $fazenda->id, 'folha_pagamento_id' => $folha->id, 'direcao' => 'a_receber', 'valor' => 3000,
            ]),
            LogicException::class
        );
    }
}
