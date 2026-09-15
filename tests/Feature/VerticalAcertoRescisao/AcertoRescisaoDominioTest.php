<?php

namespace Tests\Feature\VerticalAcertoRescisao;

use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\FormaPagamento;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\FuncionarioService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 32 (Acerto de Rescisão de Funcionário) —
 * nasce de VERTICAL-ACERTO-RESCISAO.md e SCHEMA-CONTRATO-ACERTO-RESCISAO.md.
 * Reproduz LAB-SA-022 (desligamento nunca gerava nenhum efeito financeiro).
 * O sistema nunca calcula verba rescisória — só registra os itens que o
 * produtor/contabilidade já apurou por fora.
 */
class AcertoRescisaoDominioTest extends TestCase
{
    use RefreshDatabase;

    private FuncionarioService $funcionarios;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->funcionarios = app(FuncionarioService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
    }

    private function contratarEDesligar(string $chave = 'eddie'): int
    {
        $registro = $this->funcionarios->contratar($this->jose, $this->fazenda, 'Eddie', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', $chave);
        $this->funcionarios->desligar($this->jose, $registro['funcionario']->id, '2026-07-01 08:00:00');

        return $registro['funcionario']->id;
    }

    /** LAB-SA-022 — o acerto finalmente vira despesa real, com itens separados, nunca calculados. */
    public function test_registrar_custo_desligamento_cria_uma_obrigacao_por_item(): void
    {
        $funcionarioId = $this->contratarEDesligar();

        $resultado = $this->funcionarios->registrarCustoDesligamento($this->jose, $funcionarioId, [
            ['nome' => 'Aviso Prévio', 'valor' => 3000.00, 'vencimento' => '2026-07-15'],
            ['nome' => '13º Proporcional', 'valor' => 1500.00, 'vencimento' => '2026-07-15'],
            ['nome' => 'Férias Proporcionais + 1/3', 'valor' => 2000.00, 'vencimento' => '2026-07-15'],
        ], 'acerto-eddie-1');

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertCount(3, $resultado['itens']);

        foreach ($resultado['itens'] as $item) {
            $obrigacao = ObrigacaoFinanceira::where('item_acerto_rescisao_id', $item->id)->first();
            $this->assertNotNull($obrigacao, "item_{$item->nome}_precisa_gerar_obrigacao_propria");
            $this->assertSame('a_pagar', $obrigacao->direcao);
            $this->assertSame('pendente', $obrigacao->status);

            $forma = FormaPagamento::where('obrigacao_financeira_id', $obrigacao->id)->first();
            $this->assertSame($item->nome, $forma->nome);
            $this->assertEqualsWithDelta((float) $item->valor, (float) $forma->valor, 0.01);
        }

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'custo_desligamento_registrado')->first();
        $this->assertNotNull($evento);
        $this->assertCount(3, $evento->payload['itens']);
    }

    public function test_reenvio_com_mesma_chave_nao_duplica_itens(): void
    {
        $funcionarioId = $this->contratarEDesligar();
        $itens = [['nome' => 'Aviso Prévio', 'valor' => 3000.00, 'vencimento' => '2026-07-15']];

        $primeiro = $this->funcionarios->registrarCustoDesligamento($this->jose, $funcionarioId, $itens, 'acerto-eddie-1');
        $segundo = $this->funcionarios->registrarCustoDesligamento($this->jose, $funcionarioId, $itens, 'acerto-eddie-1');

        $this->assertFalse($primeiro['reenvio_detectado']);
        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['acerto']->id, $segundo['acerto']->id);
        $this->assertSame(1, ObrigacaoFinanceira::where('item_acerto_rescisao_id', '!=', null)->count());
    }

    /** Guard de aplicação — acerto só faz sentido depois do desligamento. */
    public function test_registrar_custo_para_funcionario_ainda_ativo_e_recusado(): void
    {
        $registro = $this->funcionarios->contratar($this->jose, $this->fazenda, 'Eddie', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', 'eddie-ativo');

        $this->expectException(DomainException::class);
        $this->funcionarios->registrarCustoDesligamento($this->jose, $registro['funcionario']->id, [
            ['nome' => 'Aviso Prévio', 'valor' => 3000.00, 'vencimento' => '2026-07-15'],
        ], 'acerto-x');
    }

    public function test_registrar_custo_sem_itens_e_recusado(): void
    {
        $funcionarioId = $this->contratarEDesligar();

        $this->expectException(DomainException::class);
        $this->funcionarios->registrarCustoDesligamento($this->jose, $funcionarioId, [], 'acerto-vazio');
    }

    public function test_item_com_valor_zero_e_recusado(): void
    {
        $funcionarioId = $this->contratarEDesligar();

        $this->expectException(DomainException::class);
        $this->funcionarios->registrarCustoDesligamento($this->jose, $funcionarioId, [
            ['nome' => 'Aviso Prévio', 'valor' => 0, 'vencimento' => '2026-07-15'],
        ], 'acerto-invalido');
    }

    public function test_item_sem_nome_e_recusado(): void
    {
        $funcionarioId = $this->contratarEDesligar();

        $this->expectException(DomainException::class);
        $this->funcionarios->registrarCustoDesligamento($this->jose, $funcionarioId, [
            ['nome' => '  ', 'valor' => 1000, 'vencimento' => '2026-07-15'],
        ], 'acerto-invalido-2');
    }

    /** Nome é texto livre — mesma disciplina do achado do Vertical 18 (tipo_vacina), sem vocabulário fechado. */
    public function test_nome_do_item_aceita_qualquer_texto(): void
    {
        $funcionarioId = $this->contratarEDesligar();

        $resultado = $this->funcionarios->registrarCustoDesligamento($this->jose, $funcionarioId, [
            ['nome' => 'Multa FGTS 40% (calculada pela contabilidade externa)', 'valor' => 960.00, 'vencimento' => '2026-07-15'],
        ], 'acerto-texto-livre');

        $this->assertSame('Multa FGTS 40% (calculada pela contabilidade externa)', $resultado['itens'][0]->nome);
    }

    /** desligar() continua idêntico ao Vertical 10 — nenhuma regressão. */
    public function test_desligar_continua_funcionando_sem_registrar_custo(): void
    {
        $registro = $this->funcionarios->contratar($this->jose, $this->fazenda, 'Eddie', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', 'eddie-sem-custo');
        $funcionario = $this->funcionarios->desligar($this->jose, $registro['funcionario']->id, '2026-07-01 08:00:00');

        $this->assertSame('desligado', $funcionario->status);
        $this->assertSame(0, ObrigacaoFinanceira::where('item_acerto_rescisao_id', '!=', null)->count());
    }
}
