<?php

namespace Tests\Feature\VerticalParceirosComissao;

use App\Models\Comissao;
use App\Models\Indicacao;
use App\Models\Parceiro;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer Service — SCHEMA-CONTRATO-PARCEIROS-
 * COMISSAO.md. Mesma filosofia dos 27 verticais anteriores: testa que o
 * schema em si (migrations) só permite os estados que o contrato descreve
 * — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_parceiro_aceita_criacao_valida_sem_crm_crc(): void
    {
        $parceiro = Parceiro::create(['nome' => 'Dr. Contador']);

        $this->assertNull($parceiro->fresh()->crm_crc);
    }

    public function test_parceiro_aceita_crm_crc(): void
    {
        $parceiro = Parceiro::create(['nome' => 'Dra. Veterinária', 'crm_crc' => 'CRMV-1234']);

        $this->assertSame('CRMV-1234', $parceiro->fresh()->crm_crc);
    }

    public function test_indicacao_aceita_criacao_valida_sem_documento(): void
    {
        $parceiro = Parceiro::create(['nome' => 'Dr. Contador']);

        $indicacao = Indicacao::create([
            'parceiro_id' => $parceiro->id, 'cliente_nome' => 'Fazenda Alegria', 'cliente_documento' => null,
            'data_indicacao' => now(), 'chave_idempotencia' => 'chave-1', 'confirmada_em' => null,
        ]);

        $this->assertNotNull($indicacao->fresh());
        $this->assertNull($indicacao->confirmada_em);
    }

    public function test_indicacao_chave_idempotencia_e_unica_por_parceiro(): void
    {
        $parceiro = Parceiro::create(['nome' => 'Dr. Contador']);
        Indicacao::create(['parceiro_id' => $parceiro->id, 'cliente_nome' => 'A', 'data_indicacao' => now(), 'chave_idempotencia' => 'chave-1']);

        $this->expectException(QueryException::class);
        Indicacao::create(['parceiro_id' => $parceiro->id, 'cliente_nome' => 'B', 'data_indicacao' => now(), 'chave_idempotencia' => 'chave-1']);
    }

    public function test_mesma_chave_idempotencia_e_permitida_pra_outro_parceiro(): void
    {
        $parceiro1 = Parceiro::create(['nome' => 'Dr. Contador']);
        $parceiro2 = Parceiro::create(['nome' => 'Dra. Veterinária']);
        Indicacao::create(['parceiro_id' => $parceiro1->id, 'cliente_nome' => 'A', 'data_indicacao' => now(), 'chave_idempotencia' => 'chave-1']);

        $indicacao2 = Indicacao::create(['parceiro_id' => $parceiro2->id, 'cliente_nome' => 'B', 'data_indicacao' => now(), 'chave_idempotencia' => 'chave-1']);

        $this->assertNotNull($indicacao2->fresh());
    }

    /** INV-058 — confirmada_em é terminal. */
    public function test_indicacao_confirmada_nunca_aceita_novo_update_de_confirmada_em(): void
    {
        $parceiro = Parceiro::create(['nome' => 'Dr. Contador']);
        $indicacao = Indicacao::create([
            'parceiro_id' => $parceiro->id, 'cliente_nome' => 'A', 'data_indicacao' => now(),
            'chave_idempotencia' => 'chave-1', 'confirmada_em' => now(),
        ]);

        $this->expectException(LogicException::class);
        $indicacao->update(['confirmada_em' => now()->addDay()]);
    }

    public function test_indicacao_confirmada_ainda_aceita_update_de_outros_campos(): void
    {
        $parceiro = Parceiro::create(['nome' => 'Dr. Contador']);
        $indicacao = Indicacao::create([
            'parceiro_id' => $parceiro->id, 'cliente_nome' => 'A', 'data_indicacao' => now(),
            'chave_idempotencia' => 'chave-1', 'confirmada_em' => now(),
        ]);

        $indicacao->update(['cliente_nome' => 'B']);

        $this->assertSame('B', $indicacao->fresh()->cliente_nome);
    }

    public function test_comissao_aceita_criacao_valida(): void
    {
        $parceiro = Parceiro::create(['nome' => 'Dr. Contador']);
        $indicacao = Indicacao::create(['parceiro_id' => $parceiro->id, 'cliente_nome' => 'A', 'data_indicacao' => now(), 'chave_idempotencia' => 'chave-1', 'confirmada_em' => now()]);

        $comissao = Comissao::create([
            'indicacao_id' => $indicacao->id, 'numero_parcela' => 1, 'valor' => 140.0,
            'percentual_aplicado' => 50.0, 'valor_mensalidade_base' => 280.0,
        ]);

        $this->assertNotNull($comissao->fresh());
    }

    /** INV-060 — numero_parcela único por Indicação. */
    public function test_numero_parcela_e_unico_por_indicacao(): void
    {
        $parceiro = Parceiro::create(['nome' => 'Dr. Contador']);
        $indicacao = Indicacao::create(['parceiro_id' => $parceiro->id, 'cliente_nome' => 'A', 'data_indicacao' => now(), 'chave_idempotencia' => 'chave-1', 'confirmada_em' => now()]);
        Comissao::create(['indicacao_id' => $indicacao->id, 'numero_parcela' => 1, 'valor' => 140.0, 'percentual_aplicado' => 50.0, 'valor_mensalidade_base' => 280.0]);

        $this->expectException(QueryException::class);
        Comissao::create(['indicacao_id' => $indicacao->id, 'numero_parcela' => 1, 'valor' => 140.0, 'percentual_aplicado' => 50.0, 'valor_mensalidade_base' => 280.0]);
    }
}
