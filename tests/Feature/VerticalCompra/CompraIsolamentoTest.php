<?php

namespace Tests\Feature\VerticalCompra;

use App\Models\Animal;
use App\Models\Compra;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\CompraService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Isolamento entre Fazendas — CompraService::registrar(). Laboratório
 * estreito, mesma disciplina do Spike 005/006 (Venda): não é lógica
 * dependente de banco (Princípio 4b não se aplica aqui), SQLite sequencial
 * é ambiente adequado. Não mistura correção, Compra de Insumo, Marketplace,
 * formação de Lote ou regra financeira nova — só a fronteira entre Fazendas.
 *
 * Objetivo único: uma Compra de uma Fazenda não atravessa a fronteira de
 * outra — nem por autorização, nem por idempotência, nem por dados
 * relacionados (Fornecedor compartilhado, Animal, Obrigação, Evento).
 */
class CompraIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    private CompraService $compras;

    private int $fazendaA;

    private int $fazendaB;

    private int $jose;

    private int $mariazinha;

    private int $fornecedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compras = app(CompraService::class);

        $this->fazendaA = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $this->fazendaB = Fazenda::create(['nome' => 'Chácara da Mariazinha'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        $this->mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $this->mariazinha, 'fazenda_id' => $this->fazendaB, 'papel' => 'dono']);

        // Fornecedor global, compartilhado pelas duas Fazendas — o modelo permite.
        $this->fornecedor = Fornecedor::create(['nome' => 'Marília'])->id;
    }

    public function test_usuario_da_fazenda_a_nao_registra_compra_na_fazenda_b(): void
    {
        try {
            $this->compras->registrar($this->jose, $this->fazendaB, $this->fornecedor, [1000.00], '2026-01-01', 'ataque-jose-em-b');
            $this->fail('esperava DomainException, nenhuma foi lançada.');
        } catch (DomainException) {
        }

        $this->assertSame(0, Compra::where('fazenda_id', $this->fazendaB)->count(), 'nenhuma_linha_criada_a1');
        $this->assertSame(0, Animal::where('fazenda_id', $this->fazendaB)->count(), 'nenhuma_linha_criada_a2');
        $this->assertSame(0, ObrigacaoFinanceira::where('fazenda_id', $this->fazendaB)->count(), 'nenhuma_linha_criada_a3');
        $this->assertSame(0, EventoDominio::where('fazenda_id', $this->fazendaB)->count(), 'nenhuma_linha_criada_a4');
    }

    /**
     * O teste mais importante — valida na PERSISTÊNCIA, não só na
     * autorização, que UNIQUE(fazenda_id, chave_idempotencia) funciona:
     * a mesma chave em Fazendas diferentes produz duas Compras
     * independentes, nunca "já existe".
     */
    public function test_mesma_chave_idempotencia_em_fazendas_diferentes_produz_duas_compras_independentes(): void
    {
        $resultadoA = $this->compras->registrar($this->jose, $this->fazendaA, $this->fornecedor, [5000.00], '2026-01-01', 'compra-001');
        $resultadoB = $this->compras->registrar($this->mariazinha, $this->fazendaB, $this->fornecedor, [8000.00], '2026-01-01', 'compra-001');

        $this->assertFalse($resultadoA['reenvio_detectado'], 'a_e_intencao_legitima_propria');
        $this->assertFalse($resultadoB['reenvio_detectado'], 'b_e_intencao_legitima_propria_nao_ja_existe');
        $this->assertNotSame($resultadoA['compra']->id, $resultadoB['compra']->id, 'compras_diferentes');
        $this->assertSame($this->fazendaA, $resultadoA['compra']->fazenda_id);
        $this->assertSame($this->fazendaB, $resultadoB['compra']->fazenda_id);
        $this->assertSame('compra-001', $resultadoA['compra']->chave_idempotencia);
        $this->assertSame('compra-001', $resultadoB['compra']->chave_idempotencia);
    }

    public function test_fornecedor_compartilhado_nao_faz_compra_aparecer_na_fazenda_errada(): void
    {
        $resultadoA = $this->compras->registrar($this->jose, $this->fazendaA, $this->fornecedor, [1000.00], '2026-01-01', 'compra-a');
        $resultadoB = $this->compras->registrar($this->mariazinha, $this->fazendaB, $this->fornecedor, [2000.00], '2026-01-01', 'compra-b');

        // Mesmo Fornecedor, duas Compras — cada uma só aparece na consulta da própria Fazenda.
        $this->assertSame(2, Compra::where('fornecedor_id', $this->fornecedor)->count());
        $this->assertSame(1, Compra::where('fornecedor_id', $this->fornecedor)->where('fazenda_id', $this->fazendaA)->count());
        $this->assertSame(1, Compra::where('fornecedor_id', $this->fornecedor)->where('fazenda_id', $this->fazendaB)->count());

        // Leitura via buscar() — Mariazinha nunca enxerga a Compra de José, mesmo pelo mesmo Fornecedor.
        $this->assertNull($this->compras->buscar($this->mariazinha, $resultadoA['compra']->id), 'leitura_cruzada_bloqueada');
        $this->assertNull($this->compras->buscar($this->jose, $resultadoB['compra']->id), 'leitura_cruzada_bloqueada_inverso');
    }

    public function test_isolamento_dos_animais_criados(): void
    {
        $resultadoA = $this->compras->registrar($this->jose, $this->fazendaA, $this->fornecedor, [1000.00, 2000.00], '2026-01-01', 'compra-a');
        $resultadoB = $this->compras->registrar($this->mariazinha, $this->fazendaB, $this->fornecedor, [3000.00], '2026-01-01', 'compra-b');

        $idsA = collect($resultadoA['animais'])->pluck('id')->all();
        $idsB = collect($resultadoB['animais'])->pluck('id')->all();

        $this->assertEmpty(array_intersect($idsA, $idsB), 'nenhum_animal_compartilhado');
        $this->assertSame(2, Animal::whereIn('id', $idsA)->where('fazenda_id', $this->fazendaA)->count());
        $this->assertSame(1, Animal::whereIn('id', $idsB)->where('fazenda_id', $this->fazendaB)->count());
        $this->assertSame(0, Animal::whereIn('id', $idsA)->where('fazenda_id', $this->fazendaB)->count(), 'animal_de_a_nunca_aparece_em_b');
        $this->assertSame(0, Animal::whereIn('id', $idsB)->where('fazenda_id', $this->fazendaA)->count(), 'animal_de_b_nunca_aparece_em_a');
    }

    public function test_obrigacao_financeira_isolada_por_fazenda(): void
    {
        $resultadoA = $this->compras->registrar($this->jose, $this->fazendaA, $this->fornecedor, [1000.00], '2026-01-01', 'compra-a');
        $resultadoB = $this->compras->registrar($this->mariazinha, $this->fazendaB, $this->fornecedor, [2000.00], '2026-01-01', 'compra-b');

        $obrigacaoA = ObrigacaoFinanceira::where('compra_id', $resultadoA['compra']->id)->first();
        $obrigacaoB = ObrigacaoFinanceira::where('compra_id', $resultadoB['compra']->id)->first();

        $this->assertSame($this->fazendaA, $obrigacaoA->fazenda_id);
        $this->assertSame($this->fazendaB, $obrigacaoB->fazenda_id);
        $this->assertSame(0, ObrigacaoFinanceira::where('fazenda_id', $this->fazendaA)->where('id', $obrigacaoB->id)->count());
        $this->assertSame(0, ObrigacaoFinanceira::where('fazenda_id', $this->fazendaB)->where('id', $obrigacaoA->id)->count());
    }

    public function test_eventos_outbox_carregam_a_fazenda_correta_nunca_inferida(): void
    {
        $this->compras->registrar($this->jose, $this->fazendaA, $this->fornecedor, [1000.00], '2026-01-01', 'compra-a');
        $this->compras->registrar($this->mariazinha, $this->fazendaB, $this->fornecedor, [2000.00], '2026-01-01', 'compra-b');

        $eventoA = EventoDominio::where('fazenda_id', $this->fazendaA)->where('tipo', 'compra_concluida')->first();
        $eventoB = EventoDominio::where('fazenda_id', $this->fazendaB)->where('tipo', 'compra_concluida')->first();

        $this->assertNotNull($eventoA);
        $this->assertNotNull($eventoB);
        $this->assertNotSame($eventoA->id, $eventoB->id);
        // Cada evento carrega fazenda_id explícito na própria linha — nenhum
        // consumidor precisaria de JOIN via Fornecedor ou usuário pra saber
        // de qual Fazenda é o evento (mesmo padrão de eventos_dominio de Venda).
        $this->assertSame($this->fazendaA, (int) $eventoA->fazenda_id);
        $this->assertSame($this->fazendaB, (int) $eventoB->fazenda_id);
    }
}
