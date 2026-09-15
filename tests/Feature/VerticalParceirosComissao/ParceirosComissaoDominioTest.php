<?php

namespace Tests\Feature\VerticalParceirosComissao;

use App\Models\Comissao;
use App\Models\Indicacao;
use App\Models\Usuario;
use App\Services\IndicacaoService;
use App\Services\ParceiroService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 28 (Parceiros/Comissão) — nasce de
 * VERTICAL-PARCEIROS-COMISSAO.md e SCHEMA-CONTRATO-PARCEIROS-COMISSAO.md.
 * Formaliza o achado de LAB-FA-033: a matemática da comissão (50%×N
 * mensalidades) é correta, mas o gatilho é sempre confirmação manual de um
 * administrador, nunca automático a partir de pagamento real.
 */
class ParceirosComissaoDominioTest extends TestCase
{
    use RefreshDatabase;

    private ParceiroService $parceiroService;

    private IndicacaoService $indicacaoService;

    private int $administradorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parceiroService = app(ParceiroService::class);
        $this->indicacaoService = app(IndicacaoService::class);
        $this->administradorId = Usuario::create(['nome' => 'Admin', 'eh_administrador' => true])->id;
    }

    public function test_cadastrar_parceiro(): void
    {
        $parceiro = $this->parceiroService->cadastrar('Dr. Contador', 'CRC-9999');

        $this->assertSame('Dr. Contador', $parceiro->nome);
        $this->assertSame('CRC-9999', $parceiro->crm_crc);
    }

    public function test_cadastrar_parceiro_nome_vazio_e_recusado(): void
    {
        $this->expectException(DomainException::class);
        $this->parceiroService->cadastrar('   ');
    }

    public function test_indicar_cliente(): void
    {
        $parceiro = $this->parceiroService->cadastrar('Dr. Contador');

        $resultado = $this->indicacaoService->indicar($parceiro->id, 'Fazenda Alegria', '12345678900', 'chave-1');

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertSame('Fazenda Alegria', $resultado['indicacao']->cliente_nome);
        $this->assertNull($resultado['indicacao']->confirmada_em);
    }

    public function test_indicar_reenvio_nao_duplica(): void
    {
        $parceiro = $this->parceiroService->cadastrar('Dr. Contador');

        $r1 = $this->indicacaoService->indicar($parceiro->id, 'Fazenda Alegria', null, 'chave-1');
        $r2 = $this->indicacaoService->indicar($parceiro->id, 'Fazenda Alegria', null, 'chave-1');

        $this->assertTrue($r2['reenvio_detectado']);
        $this->assertSame($r1['indicacao']->id, $r2['indicacao']->id);
        $this->assertSame(1, Indicacao::count());
    }

    public function test_indicar_parceiro_inexistente_e_recusado(): void
    {
        $this->expectException(DomainException::class);
        $this->indicacaoService->indicar(9999, 'Fazenda Alegria', null, 'chave-1');
    }

    /** LAB-FA-033 — R$140,00 = 50% de R$280,00, lançado 2 vezes. Confirmado ao centavo. */
    public function test_confirmar_conversao_2_parcelas_gera_matematica_exata_do_lab(): void
    {
        $parceiro = $this->parceiroService->cadastrar('Dr. Contador');
        $indicacao = $this->indicacaoService->indicar($parceiro->id, 'Fazenda Alegria', null, 'chave-1')['indicacao'];

        $resultado = $this->indicacaoService->confirmarConversao($this->administradorId, $indicacao->id, 280.00, 50.0, 2);

        $this->assertFalse($resultado['ja_confirmada']);
        $this->assertNotNull($resultado['indicacao']->confirmada_em);
        $this->assertCount(2, $resultado['comissoes']);
        foreach ($resultado['comissoes'] as $comissao) {
            $this->assertEquals(140.00, (float) $comissao->valor);
        }
        $this->assertSame([1, 2], collect($resultado['comissoes'])->pluck('numero_parcela')->all());
    }

    public function test_confirmar_conversao_3_parcelas(): void
    {
        $parceiro = $this->parceiroService->cadastrar('Dra. Veterinária');
        $indicacao = $this->indicacaoService->indicar($parceiro->id, 'Fazenda Irmã', null, 'chave-1')['indicacao'];

        $resultado = $this->indicacaoService->confirmarConversao($this->administradorId, $indicacao->id, 300.00, 50.0, 3);

        $this->assertCount(3, $resultado['comissoes']);
        $this->assertSame([1, 2, 3], collect($resultado['comissoes'])->pluck('numero_parcela')->all());
    }

    /** LAB-FA-033 — sem confirmação manual, nenhuma comissão nasce. */
    public function test_indicacao_sem_confirmacao_nao_gera_comissao(): void
    {
        $parceiro = $this->parceiroService->cadastrar('Dr. Contador');
        $this->indicacaoService->indicar($parceiro->id, 'Fazenda Alegria', null, 'chave-1');

        $this->assertSame(0, Comissao::count());
    }

    public function test_confirmar_conversao_numero_parcelas_invalido_e_recusado(): void
    {
        $parceiro = $this->parceiroService->cadastrar('Dr. Contador');
        $indicacao = $this->indicacaoService->indicar($parceiro->id, 'Fazenda Alegria', null, 'chave-1')['indicacao'];

        $this->expectException(DomainException::class);
        $this->indicacaoService->confirmarConversao($this->administradorId, $indicacao->id, 280.00, 50.0, 1);
    }

    public function test_confirmar_conversao_sem_ser_administrador_e_recusado(): void
    {
        $usuarioComum = Usuario::create(['nome' => 'Comum', 'eh_administrador' => false])->id;
        $parceiro = $this->parceiroService->cadastrar('Dr. Contador');
        $indicacao = $this->indicacaoService->indicar($parceiro->id, 'Fazenda Alegria', null, 'chave-1')['indicacao'];

        $this->expectException(DomainException::class);
        $this->indicacaoService->confirmarConversao($usuarioComum, $indicacao->id, 280.00, 50.0, 2);
    }

    /** INV-058 — confirmação repetida não reprocessa nem duplica comissões. */
    public function test_confirmar_conversao_ja_confirmada_nao_reprocessa(): void
    {
        $parceiro = $this->parceiroService->cadastrar('Dr. Contador');
        $indicacao = $this->indicacaoService->indicar($parceiro->id, 'Fazenda Alegria', null, 'chave-1')['indicacao'];

        $this->indicacaoService->confirmarConversao($this->administradorId, $indicacao->id, 280.00, 50.0, 2);
        $segunda = $this->indicacaoService->confirmarConversao($this->administradorId, $indicacao->id, 999.00, 90.0, 3);

        $this->assertTrue($segunda['ja_confirmada']);
        $this->assertSame(2, Comissao::where('indicacao_id', $indicacao->id)->count());
    }

    /** INV-059 — valores congelados, respondendo à pergunta 76 (cliente muda de plano depois, comissão não muda). */
    public function test_valores_da_comissao_ficam_congelados_apos_confirmacao(): void
    {
        $parceiro = $this->parceiroService->cadastrar('Dr. Contador');
        $indicacao = $this->indicacaoService->indicar($parceiro->id, 'Fazenda Alegria', null, 'chave-1')['indicacao'];
        $this->indicacaoService->confirmarConversao($this->administradorId, $indicacao->id, 280.00, 50.0, 2);

        // "cliente muda de plano depois" simulado por uma 2ª tentativa de confirmação com valores diferentes.
        $this->indicacaoService->confirmarConversao($this->administradorId, $indicacao->id, 500.00, 80.0, 3);

        $comissoes = Comissao::where('indicacao_id', $indicacao->id)->get();
        $this->assertSame(2, $comissoes->count());
        foreach ($comissoes as $comissao) {
            $this->assertEquals(280.00, (float) $comissao->valor_mensalidade_base);
            $this->assertEquals(50.0, (float) $comissao->percentual_aplicado);
        }
    }
}
