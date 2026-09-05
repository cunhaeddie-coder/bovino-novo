<?php

namespace Tests\Feature\VerticalFormaPagamento;

use App\Models\Compra;
use App\Models\Fazenda;
use App\Models\FormaPagamento;
use App\Models\FormaPagamentoHistorico;
use App\Models\Fornecedor;
use App\Models\ObrigacaoFinanceira;
use App\Models\Usuario;
use App\Models\Venda;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer FormaPagamentoService —
 * SCHEMA-CONTRATO-FORMA-PAGAMENTO.md. Mesma filosofia dos 3 verticais
 * anteriores: testa que o schema em si (migrations + guards de Model) só
 * permite os estados que o contrato descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    private function compra(int $fazendaId, int $fornecedorId): Compra
    {
        return Compra::create([
            'fazenda_id' => $fazendaId, 'fornecedor_id' => $fornecedorId,
            'chave_idempotencia' => 'compra-'.uniqid(), 'data_compra' => '2026-01-01', 'valor_total' => 100,
        ]);
    }

    private function venda(int $fazendaId): Venda
    {
        return Venda::create([
            'fazenda_id' => $fazendaId, 'chave_idempotencia' => 'venda-'.uniqid(),
            'animal_ids' => [], 'data_venda' => '2026-01-01 10:00:00', 'valor_bruto' => 100, 'cpv' => 0, 'deducao_fiscal' => 0,
            'fiscal_e_premissa' => true, 'receita_liquida' => 100,
        ]);
    }

    public function test_obrigacao_financeira_aceita_exatamente_um_entre_compra_compra_insumo_e_venda(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília']);
        $compra = $this->compra($fazenda->id, $fornecedor->id);
        $venda = $this->venda($fazenda->id);

        // Dois preenchidos ao mesmo tempo — recusado.
        $this->assertThrows(
            fn () => ObrigacaoFinanceira::create([
                'fazenda_id' => $fazenda->id, 'compra_id' => $compra->id, 'venda_id' => $venda->id,
                'direcao' => 'a_pagar', 'valor' => 100,
            ]),
            LogicException::class
        );

        // Nenhum preenchido — recusado.
        $this->assertThrows(
            fn () => ObrigacaoFinanceira::create(['fazenda_id' => $fazenda->id, 'direcao' => 'a_pagar', 'valor' => 100]),
            LogicException::class
        );

        // Exatamente um (venda_id) — aceito.
        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda->id, 'venda_id' => $venda->id, 'direcao' => 'a_receber', 'valor' => 100,
        ]);
        $this->assertNotNull($obrigacao->id);
    }

    public function test_direcao_precisa_corresponder_ao_lado_certo(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília']);
        $compra = $this->compra($fazenda->id, $fornecedor->id);
        $venda = $this->venda($fazenda->id);

        // compra_id com direcao=a_receber — errado, recusado.
        $this->assertThrows(
            fn () => ObrigacaoFinanceira::create([
                'fazenda_id' => $fazenda->id, 'compra_id' => $compra->id, 'direcao' => 'a_receber', 'valor' => 100,
            ]),
            LogicException::class
        );

        // venda_id com direcao=a_pagar — errado, recusado.
        $this->assertThrows(
            fn () => ObrigacaoFinanceira::create([
                'fazenda_id' => $fazenda->id, 'venda_id' => $venda->id, 'direcao' => 'a_pagar', 'valor' => 100,
            ]),
            LogicException::class
        );
    }

    public function test_vencimento_e_obrigatorio_sem_excecao_inv033(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília']);
        $compra = $this->compra($fazenda->id, $fornecedor->id);
        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda->id, 'compra_id' => $compra->id, 'direcao' => 'a_pagar', 'valor' => 100,
        ]);

        $this->assertThrows(
            fn () => FormaPagamento::create([
                'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'à vista', 'unidade' => 'dinheiro',
                'valor' => 100, 'data' => '2026-01-01', 'vencimento' => null,
            ]),
            QueryException::class
        );
    }

    public function test_unidade_so_aceita_dinheiro_ou_arroba(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília']);
        $compra = $this->compra($fazenda->id, $fornecedor->id);
        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda->id, 'compra_id' => $compra->id, 'direcao' => 'a_pagar', 'valor' => 100,
        ]);

        $this->assertThrows(
            fn () => FormaPagamento::create([
                'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'x', 'unidade' => 'boi_vivo',
                'valor' => 100, 'data' => '2026-01-01', 'vencimento' => '2026-01-01',
            ]),
            LogicException::class
        );
    }

    public function test_liquidacao_em_especie_sem_liquidar_ainda_nao_exige_animal_id(): void
    {
        // Declarar a INTENÇÃO de liquidar em espécie é permitido antes de
        // saber qual Animal — necessário pro lado a_receber, onde o Animal
        // só é criado na própria liquidação (FormaPagamentoService).
        $fazenda = Fazenda::create(['nome' => 'A']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília']);
        $compra = $this->compra($fazenda->id, $fornecedor->id);
        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda->id, 'compra_id' => $compra->id, 'direcao' => 'a_pagar', 'valor' => 100,
        ]);

        $forma = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'entrada com animal', 'unidade' => 'dinheiro',
            'valor' => 100, 'data' => '2026-01-01', 'vencimento' => '2026-01-01', 'meio_liquidacao' => 'especie',
        ]);
        $this->assertNotNull($forma->id);
    }

    public function test_liquidacao_em_especie_ja_liquidada_exige_animal_id(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília']);
        $compra = $this->compra($fazenda->id, $fornecedor->id);
        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda->id, 'compra_id' => $compra->id, 'direcao' => 'a_pagar', 'valor' => 100,
        ]);

        $this->assertThrows(
            fn () => FormaPagamento::create([
                'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'entrada com animal', 'unidade' => 'dinheiro',
                'valor' => 100, 'data' => '2026-01-01', 'vencimento' => '2026-01-01',
                'meio_liquidacao' => 'especie', 'pago_em' => '2026-01-01',
            ]),
            LogicException::class
        );
    }

    public function test_cotacao_e_valor_liquidado_so_existem_depois_de_pago_em(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília']);
        $compra = $this->compra($fazenda->id, $fornecedor->id);
        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda->id, 'compra_id' => $compra->id, 'direcao' => 'a_pagar', 'valor' => 100,
        ]);

        $this->assertThrows(
            fn () => FormaPagamento::create([
                'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'x', 'unidade' => 'arroba',
                'valor' => 100, 'data' => '2026-01-01', 'vencimento' => '2027-01-01',
                'pago_em' => null, 'cotacao_arroba_na_liquidacao' => 320.00,
            ]),
            LogicException::class
        );
    }

    public function test_arroba_liquidada_exige_cotacao(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília']);
        $compra = $this->compra($fazenda->id, $fornecedor->id);
        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda->id, 'compra_id' => $compra->id, 'direcao' => 'a_pagar', 'valor' => 100,
        ]);

        $this->assertThrows(
            fn () => FormaPagamento::create([
                'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'x', 'unidade' => 'arroba',
                'valor' => 100, 'data' => '2026-01-01', 'vencimento' => '2027-01-01', 'pago_em' => '2027-01-01',
            ]),
            LogicException::class
        );
    }

    public function test_status_e_sempre_computado_a_partir_das_formas_de_pagamento_inv032(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília']);
        $compra = $this->compra($fazenda->id, $fornecedor->id);
        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda->id, 'compra_id' => $compra->id, 'direcao' => 'a_pagar', 'valor' => 200,
        ]);

        // Nenhuma Forma de Pagamento ainda — pendente, nunca presume pago.
        $this->assertSame('pendente', $obrigacao->fresh()->status);

        $entrada = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'entrada', 'unidade' => 'dinheiro',
            'valor' => 100, 'data' => '2026-01-01', 'vencimento' => '2026-01-01', 'pago_em' => '2026-01-01',
        ]);
        $parcela = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'parcela', 'unidade' => 'dinheiro',
            'valor' => 100, 'data' => '2026-01-01', 'vencimento' => '2026-02-01',
        ]);

        // Uma paga, outra não — parcial.
        $this->assertSame('parcial', $obrigacao->fresh()->status);

        $parcela->update(['pago_em' => '2026-02-01']);

        // As duas pagas — pago.
        $this->assertSame('pago', $obrigacao->fresh()->status);
    }

    public function test_formas_pagamento_historico_exige_forma_pagamento_e_usuario_validos(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $jose = Usuario::create(['nome' => 'José']);
        $fornecedor = Fornecedor::create(['nome' => 'Marília']);
        $compra = $this->compra($fazenda->id, $fornecedor->id);
        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda->id, 'compra_id' => $compra->id, 'direcao' => 'a_pagar', 'valor' => 100,
        ]);
        $forma = FormaPagamento::create([
            'obrigacao_financeira_id' => $obrigacao->id, 'nome' => 'entrada', 'unidade' => 'dinheiro',
            'valor' => 100, 'data' => '2026-01-01', 'vencimento' => '2026-02-01',
        ]);

        $historico = FormaPagamentoHistorico::create([
            'forma_pagamento_id' => $forma->id, 'nome' => 'entrada', 'valor' => 100,
            'unidade' => 'dinheiro', 'vencimento' => '2026-02-01',
            'alterado_em' => now(), 'alterado_por' => $jose->id,
        ]);
        $this->assertNotNull($historico->id);

        $this->assertThrows(
            fn () => FormaPagamentoHistorico::create([
                'forma_pagamento_id' => 999999, 'nome' => 'x', 'valor' => 100,
                'unidade' => 'dinheiro', 'vencimento' => '2026-02-01',
                'alterado_em' => now(), 'alterado_por' => $jose->id,
            ]),
            QueryException::class
        );
    }
}
