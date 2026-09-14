<?php

namespace Tests\Feature\VerticalKyc;

use App\Models\EmbargoIbama;
use App\Models\Kyc;
use App\Models\Titular;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer Service — SCHEMA-CONTRATO-KYC.md,
 * reaberto por SCHEMA-CONTRATO-TITULAR.md §8 (Kyc agora é por Titular, não
 * por Fazenda). Mesma filosofia dos 24 verticais anteriores: testa que o
 * schema em si (migrations) só permite os estados que o contrato descreve
 * — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    private function criarTitular(string $documento = '11144477735'): Titular
    {
        return Titular::create(['documento' => $documento, 'tipo_documento' => 'cpf']);
    }

    public function test_kyc_aceita_status_aprovado(): void
    {
        $titular = $this->criarTitular();
        $kyc = Kyc::create(['titular_id' => $titular->id, 'status' => 'aprovado', 'verificado_em' => now()]);

        $this->assertSame('aprovado', $kyc->fresh()->status);
        $this->assertNull($kyc->fresh()->motivo_reprovacao);
    }

    public function test_kyc_aceita_status_reprovado_com_motivo(): void
    {
        $titular = $this->criarTitular('00000000000');
        $kyc = Kyc::create(['titular_id' => $titular->id, 'status' => 'reprovado', 'motivo_reprovacao' => 'documento_invalido', 'verificado_em' => now()]);

        $this->assertSame('reprovado', $kyc->fresh()->status);
        $this->assertSame('documento_invalido', $kyc->fresh()->motivo_reprovacao);
    }

    public function test_kyc_e_unico_por_titular(): void
    {
        $titular = $this->criarTitular();
        Kyc::create(['titular_id' => $titular->id, 'status' => 'aprovado', 'verificado_em' => now()]);

        $this->expectException(QueryException::class);
        Kyc::create(['titular_id' => $titular->id, 'status' => 'aprovado', 'verificado_em' => now()]);
    }

    public function test_kyc_sem_guard_de_terminalidade_aceita_resubmissao(): void
    {
        $titular = $this->criarTitular('00000000000');
        $kyc = Kyc::create(['titular_id' => $titular->id, 'status' => 'reprovado', 'motivo_reprovacao' => 'documento_invalido', 'verificado_em' => now()]);

        $kyc->update(['status' => 'aprovado', 'motivo_reprovacao' => null]);

        $this->assertSame('aprovado', $kyc->fresh()->status);
    }

    public function test_embargo_ibama_aceita_situacao_ativo_e_cancelado(): void
    {
        $ativo = EmbargoIbama::create(['documento' => '11144477735', 'situacao' => 'ativo']);
        $cancelado = EmbargoIbama::create(['documento' => '22255588846', 'situacao' => 'cancelado']);

        $this->assertSame('ativo', $ativo->fresh()->situacao);
        $this->assertSame('cancelado', $cancelado->fresh()->situacao);
    }
}
