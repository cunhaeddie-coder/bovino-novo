<?php

namespace Tests\Feature\VerticalFreteLogistica;

use App\Models\ComissaoPlataforma;
use App\Models\Fazenda;
use App\Models\Motorista;
use App\Models\ObrigacaoFinanceira;
use App\Models\OrdemFrete;
use App\Models\PropostaFrete;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer Service — SCHEMA-CONTRATO-FRETE-
 * LOGISTICA.md. Mesma filosofia dos 21 verticais anteriores: testa que o
 * schema em si (migrations + guards de Model) só permite os estados que o
 * contrato descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_eh_administrador_nasce_false_por_padrao(): void
    {
        $usuario = Usuario::create(['nome' => 'José']);

        $this->assertFalse($usuario->fresh()->eh_administrador);
    }

    public function test_motorista_nasce_pendente_e_aceita_fazenda_nula(): void
    {
        $usuario = Usuario::create(['nome' => 'Motorista sem vínculo']);
        $motorista = Motorista::create(['usuario_id' => $usuario->id, 'documento' => '000.000.000-00']);

        $this->assertSame('pendente', $motorista->fresh()->status);
        $this->assertNull($motorista->fresh()->fazenda_id);
    }

    public function test_motorista_aceita_vinculo_opcional_com_fazenda(): void
    {
        $fazenda = Fazenda::create(['nome' => 'Fazenda de confiança']);
        $usuario = Usuario::create(['nome' => 'Motorista vinculado']);
        $motorista = Motorista::create(['usuario_id' => $usuario->id, 'fazenda_id' => $fazenda->id, 'documento' => '11.111.111/0001-11']);

        $this->assertSame($fazenda->id, $motorista->fresh()->fazenda_id);
    }

    public function test_ordem_frete_status_aceita_exige_motorista_id_e_valor_frete(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);

        $this->expectException(LogicException::class);
        OrdemFrete::create(['fazenda_id' => $fazenda->id, 'status' => 'aceita', 'chave_idempotencia' => 'x']);
    }

    public function test_ordem_frete_status_aguardando_lance_nao_exige_motorista_nem_valor(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $ordem = OrdemFrete::create(['fazenda_id' => $fazenda->id, 'status' => 'aguardando_lance', 'chave_idempotencia' => 'x']);

        $this->assertNull($ordem->fresh()->motorista_id);
        $this->assertNull($ordem->fresh()->valor_frete);
    }

    public function test_ordem_frete_e_terminal_apos_concluida(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $usuario = Usuario::create(['nome' => 'M']);
        $motorista = Motorista::create(['usuario_id' => $usuario->id, 'documento' => 'x', 'status' => 'aprovado']);
        $ordem = OrdemFrete::create([
            'fazenda_id' => $fazenda->id, 'status' => 'concluida', 'motorista_id' => $motorista->id,
            'valor_frete' => 1000, 'chave_idempotencia' => 'y',
        ]);

        $this->expectException(LogicException::class);
        $ordem->update(['status' => 'aguardando_lance']);
    }

    public function test_ordem_frete_e_terminal_apos_cancelada(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $ordem = OrdemFrete::create(['fazenda_id' => $fazenda->id, 'status' => 'cancelada', 'chave_idempotencia' => 'z']);

        $this->expectException(LogicException::class);
        $ordem->update(['status' => 'aguardando_lance']);
    }

    public function test_proposta_frete_e_terminal_apos_aceita(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $ordem = OrdemFrete::create(['fazenda_id' => $fazenda->id, 'status' => 'aguardando_lance', 'chave_idempotencia' => 'w']);
        $usuario = Usuario::create(['nome' => 'M']);
        $motorista = Motorista::create(['usuario_id' => $usuario->id, 'documento' => 'x', 'status' => 'aprovado']);
        $proposta = PropostaFrete::create([
            'ordem_frete_id' => $ordem->id, 'motorista_id' => $motorista->id, 'valor_proposto' => 500, 'status' => 'aceita',
        ]);

        $this->expectException(LogicException::class);
        $proposta->update(['status' => 'pendente']);
    }

    public function test_obrigacao_financeira_aceita_ordem_frete_como_sexto_membro_exclusivo(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $usuario = Usuario::create(['nome' => 'M']);
        $motorista = Motorista::create(['usuario_id' => $usuario->id, 'documento' => 'x', 'status' => 'aprovado']);
        $ordem = OrdemFrete::create([
            'fazenda_id' => $fazenda->id, 'status' => 'aceita', 'motorista_id' => $motorista->id,
            'valor_frete' => 1000, 'chave_idempotencia' => 'v',
        ]);

        $obrigacao = ObrigacaoFinanceira::create([
            'fazenda_id' => $fazenda->id, 'ordem_frete_id' => $ordem->id, 'direcao' => 'a_pagar', 'valor' => 1000,
        ]);

        $this->assertSame($ordem->id, $obrigacao->fresh()->ordem_frete_id);
    }

    public function test_obrigacao_financeira_de_ordem_frete_recusa_direcao_a_receber(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $usuario = Usuario::create(['nome' => 'M']);
        $motorista = Motorista::create(['usuario_id' => $usuario->id, 'documento' => 'x', 'status' => 'aprovado']);
        $ordem = OrdemFrete::create([
            'fazenda_id' => $fazenda->id, 'status' => 'aceita', 'motorista_id' => $motorista->id,
            'valor_frete' => 1000, 'chave_idempotencia' => 'u',
        ]);

        $this->expectException(LogicException::class);
        ObrigacaoFinanceira::create(['fazenda_id' => $fazenda->id, 'ordem_frete_id' => $ordem->id, 'direcao' => 'a_receber', 'valor' => 1000]);
    }

    public function test_comissao_plataforma_e_unica_por_ordem_frete(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $usuario = Usuario::create(['nome' => 'M']);
        $motorista = Motorista::create(['usuario_id' => $usuario->id, 'documento' => 'x', 'status' => 'aprovado']);
        $ordem = OrdemFrete::create([
            'fazenda_id' => $fazenda->id, 'status' => 'aceita', 'motorista_id' => $motorista->id,
            'valor_frete' => 1000, 'chave_idempotencia' => 't',
        ]);
        ComissaoPlataforma::create(['ordem_frete_id' => $ordem->id, 'valor_comissao' => 100, 'percentual_aplicado' => 10]);

        $this->expectException(QueryException::class);
        ComissaoPlataforma::create(['ordem_frete_id' => $ordem->id, 'valor_comissao' => 100, 'percentual_aplicado' => 10]);
    }
}
