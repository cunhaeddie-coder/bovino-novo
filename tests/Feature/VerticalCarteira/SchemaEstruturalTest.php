<?php

namespace Tests\Feature\VerticalCarteira;

use App\Models\Conta;
use App\Models\Fazenda;
use App\Models\FormaPagamento;
use App\Models\Lancamento;
use App\Models\ObrigacaoFinanceira;
use App\Models\Titular;
use App\Models\Venda;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer Service — SCHEMA-CONTRATO-CARTEIRA.md.
 * Mesma filosofia dos 26 verticais anteriores: testa que o schema em si
 * (migrations) só permite os estados que o contrato descreve — nunca chama
 * um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    private function criarTitular(): Titular
    {
        return Titular::create(['documento' => '11222333000181', 'tipo_documento' => 'cnpj']);
    }

    public function test_conta_aceita_criacao_valida(): void
    {
        $titular = $this->criarTitular();

        $conta = Conta::create(['titular_id' => $titular->id, 'tipo' => 'bovino', 'nome' => 'Conta Bovino']);

        $this->assertNotNull($conta->fresh());
    }

    public function test_conta_chave_idempotencia_e_unica_por_titular(): void
    {
        $titular = $this->criarTitular();
        Conta::create(['titular_id' => $titular->id, 'tipo' => 'externa', 'nome' => 'Banco', 'chave_idempotencia' => 'c1']);

        $this->expectException(QueryException::class);
        Conta::create(['titular_id' => $titular->id, 'tipo' => 'externa', 'nome' => 'Banco 2', 'chave_idempotencia' => 'c1']);
    }

    public function test_conta_aceita_multiplas_externas_sem_chave_conflitar_com_bovino_nula(): void
    {
        $titular = $this->criarTitular();
        Conta::create(['titular_id' => $titular->id, 'tipo' => 'bovino', 'nome' => 'Conta Bovino']);
        $externa1 = Conta::create(['titular_id' => $titular->id, 'tipo' => 'externa', 'nome' => 'Banco A', 'chave_idempotencia' => 'c1']);
        $externa2 = Conta::create(['titular_id' => $titular->id, 'tipo' => 'externa', 'nome' => 'Banco B', 'chave_idempotencia' => 'c2']);

        $this->assertSame(3, Conta::where('titular_id', $titular->id)->count());
        $this->assertNotNull($externa1->fresh());
        $this->assertNotNull($externa2->fresh());
    }

    private function criarFormaPagamento(): FormaPagamento
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $venda = Venda::create([
            'fazenda_id' => $fazenda->id, 'chave_idempotencia' => 'venda-'.uniqid(),
            'animal_ids' => [], 'data_venda' => '2026-01-01 10:00:00', 'valor_bruto' => 1000, 'cpv' => 0,
            'deducao_fiscal' => 0, 'fiscal_e_premissa' => true, 'receita_liquida' => 1000,
        ]);
        $obrigacao = ObrigacaoFinanceira::create(['fazenda_id' => $fazenda->id, 'venda_id' => $venda->id, 'direcao' => 'a_receber', 'valor' => 1000]);

        return FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'à vista', 'unidade' => 'dinheiro',
            'valor' => 1000, 'data' => '2026-01-01', 'vencimento' => '2026-01-01', 'pago_em' => '2026-01-01', 'valor_liquidado_reais' => 1000,
        ]);
    }

    public function test_lancamento_automatico_aceita_criacao_valida(): void
    {
        $titular = $this->criarTitular();
        $conta = Conta::create(['titular_id' => $titular->id, 'tipo' => 'bovino', 'nome' => 'Conta Bovino']);
        $forma = $this->criarFormaPagamento();

        $lancamento = Lancamento::create([
            'conta_id' => $conta->id, 'tipo' => 'entrada', 'valor' => 1000, 'data_lancamento' => now(),
            'descricao' => 'Venda #1 liquidada', 'origem' => 'automatico', 'forma_pagamento_id' => $forma->id,
            'chave_idempotencia' => "forma-pagamento-{$forma->id}",
        ]);

        $this->assertNotNull($lancamento->fresh());
    }

    public function test_lancamento_chave_idempotencia_e_unica_por_conta(): void
    {
        $titular = $this->criarTitular();
        $conta = Conta::create(['titular_id' => $titular->id, 'tipo' => 'externa', 'nome' => 'Banco']);
        Lancamento::create([
            'conta_id' => $conta->id, 'tipo' => 'entrada', 'valor' => 500, 'data_lancamento' => now(),
            'descricao' => 'Depósito', 'origem' => 'manual', 'chave_idempotencia' => 'chave-1',
        ]);

        $this->expectException(QueryException::class);
        Lancamento::create([
            'conta_id' => $conta->id, 'tipo' => 'saida', 'valor' => 100, 'data_lancamento' => now(),
            'descricao' => 'Saque', 'origem' => 'manual', 'chave_idempotencia' => 'chave-1',
        ]);
    }

    /** INV-056 — origem=automatico sempre exige forma_pagamento_id. */
    public function test_lancamento_automatico_sem_forma_pagamento_e_recusado(): void
    {
        $titular = $this->criarTitular();
        $conta = Conta::create(['titular_id' => $titular->id, 'tipo' => 'bovino', 'nome' => 'Conta Bovino']);

        $this->expectException(LogicException::class);
        Lancamento::create([
            'conta_id' => $conta->id, 'tipo' => 'entrada', 'valor' => 1000, 'data_lancamento' => now(),
            'descricao' => 'x', 'origem' => 'automatico', 'forma_pagamento_id' => null,
            'chave_idempotencia' => 'chave-x',
        ]);
    }

    /** INV-056 — origem=manual nunca pode ter forma_pagamento_id. */
    public function test_lancamento_manual_com_forma_pagamento_e_recusado(): void
    {
        $titular = $this->criarTitular();
        $conta = Conta::create(['titular_id' => $titular->id, 'tipo' => 'externa', 'nome' => 'Banco']);

        $this->expectException(LogicException::class);
        Lancamento::create([
            'conta_id' => $conta->id, 'tipo' => 'entrada', 'valor' => 1000, 'data_lancamento' => now(),
            'descricao' => 'x', 'origem' => 'manual', 'forma_pagamento_id' => 999,
            'chave_idempotencia' => 'chave-y',
        ]);
    }

    public function test_lancamento_tipo_invalido_e_recusado(): void
    {
        $titular = $this->criarTitular();
        $conta = Conta::create(['titular_id' => $titular->id, 'tipo' => 'externa', 'nome' => 'Banco']);

        $this->expectException(LogicException::class);
        Lancamento::create([
            'conta_id' => $conta->id, 'tipo' => 'transferencia', 'valor' => 1000, 'data_lancamento' => now(),
            'descricao' => 'x', 'origem' => 'manual', 'chave_idempotencia' => 'chave-z',
        ]);
    }

    public function test_conta_saldo_e_computado_a_partir_dos_lancamentos(): void
    {
        $titular = $this->criarTitular();
        $conta = Conta::create(['titular_id' => $titular->id, 'tipo' => 'externa', 'nome' => 'Banco']);
        Lancamento::create(['conta_id' => $conta->id, 'tipo' => 'entrada', 'valor' => 1000, 'data_lancamento' => now(), 'descricao' => 'a', 'origem' => 'manual', 'chave_idempotencia' => 'c1']);
        Lancamento::create(['conta_id' => $conta->id, 'tipo' => 'saida', 'valor' => 300, 'data_lancamento' => now(), 'descricao' => 'b', 'origem' => 'manual', 'chave_idempotencia' => 'c2']);

        $this->assertEquals(700.0, $conta->fresh()->saldo);
    }
}
