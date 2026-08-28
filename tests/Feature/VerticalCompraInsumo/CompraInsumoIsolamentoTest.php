<?php

namespace Tests\Feature\VerticalCompraInsumo;

use App\Models\CompraInsumo;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Insumo;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\CompraInsumoService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Isolamento entre Fazendas — CompraInsumoService::registrar(). Laboratório
 * estreito, mesma disciplina do Spike 005/006 (Venda) e de
 * CompraIsolamentoTest (Compra de Animal): não é lógica dependente de
 * banco (Princípio 4b não se aplica aqui), SQLite sequencial é ambiente
 * adequado. Não mistura concorrência (já provada, INV-030), correção,
 * ciclo integrado ou regra financeira nova — só a fronteira entre Fazendas.
 *
 * Objetivo único: uma Compra de Insumo de uma Fazenda não atravessa a
 * fronteira de outra — nem por autorização, nem por idempotência, nem por
 * dados relacionados (Fornecedor compartilhado, Insumo, Obrigação, Evento).
 */
class CompraInsumoIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    private CompraInsumoService $compras;

    private int $fazendaA;

    private int $fazendaB;

    private int $jose;

    private int $mariazinha;

    private int $fornecedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compras = app(CompraInsumoService::class);

        $this->fazendaA = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $this->fazendaB = Fazenda::create(['nome' => 'Chácara da Mariazinha'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        $this->mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $this->mariazinha, 'fazenda_id' => $this->fazendaB, 'papel' => 'dono']);

        // Fornecedor global, compartilhado pelas duas Fazendas — o modelo permite.
        $this->fornecedor = Fornecedor::create(['nome' => 'Marília'])->id;
    }

    private function item(string $nome, float $quantidade, float $valorUnitario): array
    {
        return ['insumo_novo' => ['nome' => $nome], 'quantidade' => $quantidade, 'valor_unitario' => $valorUnitario];
    }

    public function test_usuario_da_fazenda_a_nao_registra_compra_de_insumo_na_fazenda_b(): void
    {
        try {
            $this->compras->registrar($this->jose, $this->fazendaB, $this->fornecedor, [$this->item('Sal', 10, 17.99)], '2026-01-01', 'ataque-jose-em-b');
            $this->fail('esperava DomainException, nenhuma foi lançada.');
        } catch (DomainException) {
        }

        $this->assertSame(0, CompraInsumo::where('fazenda_id', $this->fazendaB)->count(), 'nenhuma_linha_criada_a1');
        $this->assertSame(0, Insumo::where('fazenda_id', $this->fazendaB)->count(), 'nenhuma_linha_criada_a2');
        $this->assertSame(0, ObrigacaoFinanceira::where('fazenda_id', $this->fazendaB)->count(), 'nenhuma_linha_criada_a3');
        $this->assertSame(0, EventoDominio::where('fazenda_id', $this->fazendaB)->count(), 'nenhuma_linha_criada_a4');
    }

    /**
     * O teste mais importante — valida na PERSISTÊNCIA, não só na
     * autorização, que UNIQUE(fazenda_id, chave_idempotencia) funciona:
     * a mesma chave em Fazendas diferentes produz duas CompraInsumo
     * independentes, nunca "já existe".
     */
    public function test_mesma_chave_idempotencia_em_fazendas_diferentes_produz_duas_compras_independentes(): void
    {
        $resultadoA = $this->compras->registrar($this->jose, $this->fazendaA, $this->fornecedor, [$this->item('Sal Branco 25kg', 80, 17.99)], '2026-01-01', 'compra-001');
        $resultadoB = $this->compras->registrar($this->mariazinha, $this->fazendaB, $this->fornecedor, [$this->item('Sal Branco 25kg', 50, 18.50)], '2026-01-01', 'compra-001');

        $this->assertFalse($resultadoA['reenvio_detectado'], 'a_e_intencao_legitima_propria');
        $this->assertFalse($resultadoB['reenvio_detectado'], 'b_e_intencao_legitima_propria_nao_ja_existe');
        $this->assertNotSame($resultadoA['compra']->id, $resultadoB['compra']->id, 'compras_diferentes');
        $this->assertSame($this->fazendaA, $resultadoA['compra']->fazenda_id);
        $this->assertSame($this->fazendaB, $resultadoB['compra']->fazenda_id);
        $this->assertSame('compra-001', $resultadoA['compra']->chave_idempotencia);
        $this->assertSame('compra-001', $resultadoB['compra']->chave_idempotencia);

        // Mesmo nome de Insumo ("Sal Branco 25kg") nas duas Fazendas — catálogos independentes, UNIQUE(fazenda_id, nome) permite.
        $this->assertSame(2, Insumo::where('nome', 'Sal Branco 25kg')->count(), 'catalogos_independentes_nao_colidem');
    }

    public function test_fornecedor_compartilhado_nao_faz_compra_aparecer_na_fazenda_errada(): void
    {
        $resultadoA = $this->compras->registrar($this->jose, $this->fazendaA, $this->fornecedor, [$this->item('Sal', 10, 17.99)], '2026-01-01', 'compra-a');
        $resultadoB = $this->compras->registrar($this->mariazinha, $this->fazendaB, $this->fornecedor, [$this->item('Fosbovi', 5, 220.00)], '2026-01-01', 'compra-b');

        // Mesmo Fornecedor, duas Compras — cada uma só aparece na consulta da própria Fazenda.
        $this->assertSame(2, CompraInsumo::where('fornecedor_id', $this->fornecedor)->count());
        $this->assertSame(1, CompraInsumo::where('fornecedor_id', $this->fornecedor)->where('fazenda_id', $this->fazendaA)->count());
        $this->assertSame(1, CompraInsumo::where('fornecedor_id', $this->fornecedor)->where('fazenda_id', $this->fazendaB)->count());

        // Leitura via buscar() — Mariazinha nunca enxerga a Compra de José, mesmo pelo mesmo Fornecedor.
        $this->assertNull($this->compras->buscar($this->mariazinha, $resultadoA['compra']->id), 'leitura_cruzada_bloqueada');
        $this->assertNull($this->compras->buscar($this->jose, $resultadoB['compra']->id), 'leitura_cruzada_bloqueada_inverso');
    }

    public function test_isolamento_dos_insumos_criados(): void
    {
        $resultadoA = $this->compras->registrar($this->jose, $this->fazendaA, $this->fornecedor, [$this->item('Sal', 10, 17.99), $this->item('Fosbovi', 5, 220.00)], '2026-01-01', 'compra-a');
        $resultadoB = $this->compras->registrar($this->mariazinha, $this->fazendaB, $this->fornecedor, [$this->item('Vermífugo', 3, 32.00)], '2026-01-01', 'compra-b');

        $idsA = collect($resultadoA['insumos'])->pluck('id')->all();
        $idsB = collect($resultadoB['insumos'])->pluck('id')->all();

        $this->assertEmpty(array_intersect($idsA, $idsB), 'nenhum_insumo_compartilhado');
        $this->assertSame(2, Insumo::whereIn('id', $idsA)->where('fazenda_id', $this->fazendaA)->count());
        $this->assertSame(1, Insumo::whereIn('id', $idsB)->where('fazenda_id', $this->fazendaB)->count());
        $this->assertSame(0, Insumo::whereIn('id', $idsA)->where('fazenda_id', $this->fazendaB)->count(), 'insumo_de_a_nunca_aparece_em_b');
        $this->assertSame(0, Insumo::whereIn('id', $idsB)->where('fazenda_id', $this->fazendaA)->count(), 'insumo_de_b_nunca_aparece_em_a');
    }

    public function test_obrigacao_financeira_isolada_por_fazenda(): void
    {
        $resultadoA = $this->compras->registrar($this->jose, $this->fazendaA, $this->fornecedor, [$this->item('Sal', 10, 17.99)], '2026-01-01', 'compra-a');
        $resultadoB = $this->compras->registrar($this->mariazinha, $this->fazendaB, $this->fornecedor, [$this->item('Fosbovi', 5, 220.00)], '2026-01-01', 'compra-b');

        $obrigacaoA = ObrigacaoFinanceira::where('compra_insumo_id', $resultadoA['compra']->id)->first();
        $obrigacaoB = ObrigacaoFinanceira::where('compra_insumo_id', $resultadoB['compra']->id)->first();

        $this->assertSame($this->fazendaA, $obrigacaoA->fazenda_id);
        $this->assertSame($this->fazendaB, $obrigacaoB->fazenda_id);
        $this->assertSame(0, ObrigacaoFinanceira::where('fazenda_id', $this->fazendaA)->where('id', $obrigacaoB->id)->count());
        $this->assertSame(0, ObrigacaoFinanceira::where('fazenda_id', $this->fazendaB)->where('id', $obrigacaoA->id)->count());
    }

    public function test_eventos_outbox_carregam_a_fazenda_correta_nunca_inferida(): void
    {
        $this->compras->registrar($this->jose, $this->fazendaA, $this->fornecedor, [$this->item('Sal', 10, 17.99)], '2026-01-01', 'compra-a');
        $this->compras->registrar($this->mariazinha, $this->fazendaB, $this->fornecedor, [$this->item('Fosbovi', 5, 220.00)], '2026-01-01', 'compra-b');

        $eventoA = EventoDominio::where('fazenda_id', $this->fazendaA)->where('tipo', 'compra_insumo_concluida')->first();
        $eventoB = EventoDominio::where('fazenda_id', $this->fazendaB)->where('tipo', 'compra_insumo_concluida')->first();

        $this->assertNotNull($eventoA);
        $this->assertNotNull($eventoB);
        $this->assertNotSame($eventoA->id, $eventoB->id);
        $this->assertSame($this->fazendaA, (int) $eventoA->fazenda_id);
        $this->assertSame($this->fazendaB, (int) $eventoB->fazenda_id);
    }
}
