<?php

namespace Tests\Feature\VerticalKyc;

use App\Models\EmbargoIbama;
use App\Models\Fazenda;
use App\Models\Kyc;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer Service — SCHEMA-CONTRATO-KYC.md.
 * Mesma filosofia dos 22 verticais anteriores: testa que o schema em si
 * (migrations + guards de Model) só permite os estados que o contrato
 * descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_kyc_aceita_status_aprovado(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $kyc = Kyc::create(['fazenda_id' => $fazenda->id, 'documento' => '11144477735', 'tipo_documento' => 'cpf', 'status' => 'aprovado', 'verificado_em' => now()]);

        $this->assertSame('aprovado', $kyc->fresh()->status);
        $this->assertNull($kyc->fresh()->motivo_reprovacao);
    }

    public function test_kyc_aceita_status_reprovado_com_motivo(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $kyc = Kyc::create(['fazenda_id' => $fazenda->id, 'documento' => '00000000000', 'tipo_documento' => 'cpf', 'status' => 'reprovado', 'motivo_reprovacao' => 'documento_invalido', 'verificado_em' => now()]);

        $this->assertSame('reprovado', $kyc->fresh()->status);
        $this->assertSame('documento_invalido', $kyc->fresh()->motivo_reprovacao);
    }

    public function test_kyc_e_unico_por_fazenda(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        Kyc::create(['fazenda_id' => $fazenda->id, 'documento' => '11144477735', 'tipo_documento' => 'cpf', 'status' => 'aprovado', 'verificado_em' => now()]);

        $this->expectException(QueryException::class);
        Kyc::create(['fazenda_id' => $fazenda->id, 'documento' => '22255588846', 'tipo_documento' => 'cpf', 'status' => 'aprovado', 'verificado_em' => now()]);
    }

    public function test_kyc_sem_guard_de_terminalidade_aceita_resubmissao(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $kyc = Kyc::create(['fazenda_id' => $fazenda->id, 'documento' => '00000000000', 'tipo_documento' => 'cpf', 'status' => 'reprovado', 'motivo_reprovacao' => 'documento_invalido', 'verificado_em' => now()]);

        $kyc->update(['status' => 'aprovado', 'motivo_reprovacao' => null, 'documento' => '11144477735']);

        $this->assertSame('aprovado', $kyc->fresh()->status);
    }

    public function test_embargo_ibama_aceita_situacao_ativo_e_cancelado(): void
    {
        $ativo = EmbargoIbama::create(['documento' => '11144477735', 'situacao' => 'ativo']);
        $cancelado = EmbargoIbama::create(['documento' => '22255588846', 'situacao' => 'cancelado']);

        $this->assertSame('ativo', $ativo->fresh()->situacao);
        $this->assertSame('cancelado', $cancelado->fresh()->situacao);
    }

    public function test_kyc_nao_precisa_de_relacao_com_usuario_no_schema(): void
    {
        // O schema em si (kycs) não tem FK pra usuarios — o vínculo de
        // autorização vive no Service (temRelacaoComFazenda via Papel),
        // não no schema de Kyc.
        Usuario::create(['nome' => 'X']);
        $fazenda = Fazenda::create(['nome' => 'A']);
        $kyc = Kyc::create(['fazenda_id' => $fazenda->id, 'documento' => '11144477735', 'tipo_documento' => 'cpf', 'status' => 'aprovado', 'verificado_em' => now()]);

        $this->assertSame($fazenda->id, $kyc->fresh()->fazenda_id);
    }
}
