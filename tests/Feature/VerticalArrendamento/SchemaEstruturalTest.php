<?php

namespace Tests\Feature\VerticalArrendamento;

use App\Models\Arrendamento;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\ObrigacaoFinanceira;
use App\Models\ParcelaArrendamento;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer ArrendamentoService —
 * SCHEMA-CONTRATO-ARRENDAMENTO.md. Mesma filosofia dos 11 verticais
 * anteriores: testa que o schema em si (migrations + guards de Model) só
 * permite os estados que o contrato descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    private function criarArrendamento(int $fazendaId): Arrendamento
    {
        $fornecedor = Fornecedor::create(['nome' => 'Zé da Terra']);

        return Arrendamento::create([
            'fazenda_id' => $fazendaId, 'fornecedor_id' => $fornecedor->id, 'valor_total' => 54000,
            'periodicidade' => 'anual', 'data_inicio' => '2026-01-01 08:00:00', 'data_fim' => '2028-01-01 08:00:00',
            'chave_idempotencia' => 'x',
        ]);
    }

    public function test_arrendamento_e_imutavel_depois_de_registrado(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $arrendamento = $this->criarArrendamento($fazenda->id);

        $this->assertThrows(
            fn () => $arrendamento->update(['valor_total' => 60000]),
            LogicException::class
        );
    }

    public function test_arrendamento_e_unico_por_fazenda_e_chave_idempotencia(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $fornecedor = Fornecedor::create(['nome' => 'Zé da Terra']);
        Arrendamento::create([
            'fazenda_id' => $fazenda->id, 'fornecedor_id' => $fornecedor->id, 'valor_total' => 54000,
            'periodicidade' => 'anual', 'data_inicio' => '2026-01-01 08:00:00', 'data_fim' => '2028-01-01 08:00:00',
            'chave_idempotencia' => 'mesma-chave',
        ]);

        $this->assertThrows(
            fn () => Arrendamento::create([
                'fazenda_id' => $fazenda->id, 'fornecedor_id' => $fornecedor->id, 'valor_total' => 10000,
                'periodicidade' => 'mensal', 'data_inicio' => '2026-02-01 08:00:00', 'data_fim' => '2026-06-01 08:00:00',
                'chave_idempotencia' => 'mesma-chave',
            ]),
            QueryException::class
        );
    }

    public function test_parcela_arrendamento_e_imutavel_depois_de_gerada(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $arrendamento = $this->criarArrendamento($fazenda->id);
        $parcela = ParcelaArrendamento::create([
            'fazenda_id' => $fazenda->id, 'arrendamento_id' => $arrendamento->id, 'numero_parcela' => 1,
            'valor' => 27000, 'data_geracao' => '2027-01-01 08:00:00',
        ]);

        $this->assertThrows(
            fn () => $parcela->update(['valor' => 30000]),
            LogicException::class
        );
    }

    public function test_parcela_arrendamento_e_unica_por_arrendamento_e_numero(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $arrendamento = $this->criarArrendamento($fazenda->id);
        ParcelaArrendamento::create([
            'fazenda_id' => $fazenda->id, 'arrendamento_id' => $arrendamento->id, 'numero_parcela' => 1,
            'valor' => 27000, 'data_geracao' => '2027-01-01 08:00:00',
        ]);

        $this->assertThrows(
            fn () => ParcelaArrendamento::create([
                'fazenda_id' => $fazenda->id, 'arrendamento_id' => $arrendamento->id, 'numero_parcela' => 1,
                'valor' => 27000, 'data_geracao' => '2027-01-02 08:00:00',
            ]),
            QueryException::class
        );
    }

    /** ObrigacaoFinanceira::booted() estendido pro 5º membro — parcela_arrendamento_id. */
    public function test_obrigacao_financeira_aceita_parcela_arrendamento_id_como_5o_membro(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $arrendamento = $this->criarArrendamento($fazenda->id);
        $parcela = ParcelaArrendamento::create([
            'fazenda_id' => $fazenda->id, 'arrendamento_id' => $arrendamento->id, 'numero_parcela' => 1,
            'valor' => 27000, 'data_geracao' => '2027-01-01 08:00:00',
        ]);

        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda->id, 'parcela_arrendamento_id' => $parcela->id, 'direcao' => 'a_pagar', 'valor' => 27000,
        ]);

        $this->assertSame($parcela->id, $obrigacao->parcela_arrendamento_id);
        $this->assertSame('pendente', $obrigacao->status);
    }

    public function test_obrigacao_financeira_de_parcela_arrendamento_exige_direcao_a_pagar(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $arrendamento = $this->criarArrendamento($fazenda->id);
        $parcela = ParcelaArrendamento::create([
            'fazenda_id' => $fazenda->id, 'arrendamento_id' => $arrendamento->id, 'numero_parcela' => 1,
            'valor' => 27000, 'data_geracao' => '2027-01-01 08:00:00',
        ]);

        $this->assertThrows(
            fn () => ObrigacaoFinanceira::create([
                'fazenda_id' => $fazenda->id, 'parcela_arrendamento_id' => $parcela->id, 'direcao' => 'a_receber', 'valor' => 27000,
            ]),
            LogicException::class
        );
    }
}
