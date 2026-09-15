<?php

namespace Tests\Feature\VerticalAcertoRescisao;

use App\Models\AcertoRescisao;
use App\Models\Fazenda;
use App\Models\Funcionario;
use App\Models\ItemAcertoRescisao;
use App\Models\ObrigacaoFinanceira;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer Service — SCHEMA-CONTRATO-ACERTO-
 * RESCISAO.md. Mesma filosofia dos 31 verticais anteriores: testa que o
 * schema em si (migrations) só permite os estados que o contrato descreve
 * — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    private function criarFuncionario(int $fazendaId): Funcionario
    {
        return Funcionario::create([
            'fazenda_id' => $fazendaId, 'nome' => 'Eddie', 'salario' => 3000, 'status' => 'desligado',
            'data_contratacao' => '2026-01-01 08:00:00', 'data_desligamento' => '2026-07-01 08:00:00',
            'chave_idempotencia' => 'func-1',
        ]);
    }

    public function test_acerto_rescisao_aceita_criacao_valida(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $funcionario = $this->criarFuncionario($fazenda->id);

        $acerto = AcertoRescisao::create(['fazenda_id' => $fazenda->id, 'funcionario_id' => $funcionario->id, 'chave_idempotencia' => 'acerto-1']);

        $this->assertNotNull($acerto->fresh());
    }

    public function test_acerto_rescisao_chave_idempotencia_e_unica_por_funcionario(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $funcionario = $this->criarFuncionario($fazenda->id);
        AcertoRescisao::create(['fazenda_id' => $fazenda->id, 'funcionario_id' => $funcionario->id, 'chave_idempotencia' => 'acerto-1']);

        $this->expectException(QueryException::class);
        AcertoRescisao::create(['fazenda_id' => $fazenda->id, 'funcionario_id' => $funcionario->id, 'chave_idempotencia' => 'acerto-1']);
    }

    public function test_item_acerto_rescisao_aceita_criacao_valida(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $funcionario = $this->criarFuncionario($fazenda->id);
        $acerto = AcertoRescisao::create(['fazenda_id' => $fazenda->id, 'funcionario_id' => $funcionario->id, 'chave_idempotencia' => 'acerto-1']);

        $item = ItemAcertoRescisao::create([
            'fazenda_id' => $fazenda->id, 'acerto_rescisao_id' => $acerto->id,
            'nome' => 'Aviso Prévio', 'valor' => 3000, 'vencimento' => '2026-08-01',
        ]);

        $this->assertNotNull($item->fresh());
        $this->assertSame('Aviso Prévio', $item->nome);
    }

    /** INV-026-like — AcertoRescisao/ItemAcertoRescisao são imutáveis. */
    public function test_acerto_rescisao_nunca_aceita_update(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $funcionario = $this->criarFuncionario($fazenda->id);
        $acerto = AcertoRescisao::create(['fazenda_id' => $fazenda->id, 'funcionario_id' => $funcionario->id, 'chave_idempotencia' => 'acerto-1']);

        $this->expectException(LogicException::class);
        $acerto->update(['chave_idempotencia' => 'outra']);
    }

    public function test_item_acerto_rescisao_nunca_aceita_update(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $funcionario = $this->criarFuncionario($fazenda->id);
        $acerto = AcertoRescisao::create(['fazenda_id' => $fazenda->id, 'funcionario_id' => $funcionario->id, 'chave_idempotencia' => 'acerto-1']);
        $item = ItemAcertoRescisao::create([
            'fazenda_id' => $fazenda->id, 'acerto_rescisao_id' => $acerto->id,
            'nome' => 'Aviso Prévio', 'valor' => 3000, 'vencimento' => '2026-08-01',
        ]);

        $this->expectException(LogicException::class);
        $item->update(['valor' => 5000]);
    }

    /** item_acerto_rescisao_id é o 7º membro do grupo mutuamente exclusivo. */
    public function test_obrigacao_financeira_aceita_item_acerto_rescisao_id(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $funcionario = $this->criarFuncionario($fazenda->id);
        $acerto = AcertoRescisao::create(['fazenda_id' => $fazenda->id, 'funcionario_id' => $funcionario->id, 'chave_idempotencia' => 'acerto-1']);
        $item = ItemAcertoRescisao::create([
            'fazenda_id' => $fazenda->id, 'acerto_rescisao_id' => $acerto->id,
            'nome' => 'Aviso Prévio', 'valor' => 3000, 'vencimento' => '2026-08-01',
        ]);

        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda->id, 'item_acerto_rescisao_id' => $item->id, 'direcao' => 'a_pagar', 'valor' => 3000,
        ]);

        $this->assertNotNull($obrigacao->fresh());
    }

    public function test_obrigacao_financeira_de_item_acerto_rescisao_exige_direcao_a_pagar(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $funcionario = $this->criarFuncionario($fazenda->id);
        $acerto = AcertoRescisao::create(['fazenda_id' => $fazenda->id, 'funcionario_id' => $funcionario->id, 'chave_idempotencia' => 'acerto-1']);
        $item = ItemAcertoRescisao::create([
            'fazenda_id' => $fazenda->id, 'acerto_rescisao_id' => $acerto->id,
            'nome' => 'Aviso Prévio', 'valor' => 3000, 'vencimento' => '2026-08-01',
        ]);

        $this->expectException(LogicException::class);
        ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda->id, 'item_acerto_rescisao_id' => $item->id, 'direcao' => 'a_receber', 'valor' => 3000,
        ]);
    }

    public function test_obrigacao_financeira_exige_exatamente_um_membro_do_grupo(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);

        $this->expectException(LogicException::class);
        ObrigacaoFinanceira::create(['fazenda_id' => $fazenda->id, 'direcao' => 'a_pagar', 'valor' => 3000]);
    }
}
