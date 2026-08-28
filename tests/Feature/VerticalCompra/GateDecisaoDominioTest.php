<?php

namespace Tests\Feature\VerticalCompra;

use App\Models\Animal;
use App\Models\Compra;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Lote;
use App\Models\Papel;
use App\Models\Usuario;
use App\Models\Venda;
use App\Services\CompraService;
use App\Services\VendaService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GATE-DECISAO-DOMINIO-COMPRA.md (27/08/2026) — trava as três decisões do
 * produtor como regressão permanente: preço <= 0 nunca é uma Compra válida
 * (é um fato diferente, Doação, fora de escopo — MAPA-DOMINIO.md), e
 * chave_idempotencia vazia é sempre recusada, simetricamente em Compra e
 * Venda (registrar() e corrigir()).
 */
class GateDecisaoDominioTest extends TestCase
{
    use RefreshDatabase;

    private CompraService $compras;

    private VendaService $vendas;

    private int $fazenda;

    private int $jose;

    private int $marilia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compras = app(CompraService::class);
        $this->vendas = app(VendaService::class);

        $this->fazenda = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->marilia = Fornecedor::create(['nome' => 'Marília'])->id;
    }

    public function test_compra_recusa_preco_zero(): void
    {
        $this->assertThrows(
            fn () => $this->compras->registrar($this->jose, $this->fazenda, $this->marilia, [0.00], '2026-01-01', 'compra-preco-zero'),
            DomainException::class
        );

        $this->assertSame(0, Compra::count());
        $this->assertSame(0, Animal::count());
    }

    public function test_compra_recusa_preco_negativo(): void
    {
        $this->assertThrows(
            fn () => $this->compras->registrar($this->jose, $this->fazenda, $this->marilia, [-100.00], '2026-01-01', 'compra-preco-negativo'),
            DomainException::class
        );

        $this->assertSame(0, Compra::count());
        $this->assertSame(0, Animal::count());
    }

    /** Um preço válido entre outros negativos ainda deve recusar a Compra inteira — sem escrita parcial. */
    public function test_compra_com_um_preco_invalido_entre_validos_recusa_tudo(): void
    {
        $this->assertThrows(
            fn () => $this->compras->registrar($this->jose, $this->fazenda, $this->marilia, [5000.00, 0.00, 3000.00], '2026-01-01', 'compra-mista-invalida'),
            DomainException::class
        );

        $this->assertSame(0, Compra::count());
        $this->assertSame(0, Animal::count());
    }

    public function test_compra_recusa_chave_idempotencia_vazia(): void
    {
        $this->assertThrows(
            fn () => $this->compras->registrar($this->jose, $this->fazenda, $this->marilia, [1000.00], '2026-01-01', ''),
            DomainException::class
        );

        $this->assertSame(0, Compra::count());
    }

    public function test_venda_recusa_chave_idempotencia_vazia_em_registrar(): void
    {
        $lote = Lote::create(['fazenda_id' => $this->fazenda, 'qtd_animais' => 1, 'custo_aquisicao' => 200])->id;
        $animal = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => $lote, 'status' => 'ativo'])->id;

        $this->assertThrows(
            fn () => $this->vendas->registrar($this->jose, $this->fazenda, [$animal], 1000.00, ''),
            DomainException::class
        );

        $this->assertSame(0, Venda::count());
        $this->assertSame('ativo', Animal::find($animal)->status, 'animal não pode ser afetado por uma venda recusada');
    }

    public function test_venda_recusa_chave_idempotencia_vazia_em_corrigir(): void
    {
        $lote = Lote::create(['fazenda_id' => $this->fazenda, 'qtd_animais' => 2, 'custo_aquisicao' => 200])->id;
        $a1 = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => $lote, 'status' => 'ativo'])->id;
        $a2 = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => $lote, 'status' => 'ativo'])->id;

        $venda = $this->vendas->registrar($this->jose, $this->fazenda, [$a1, $a2], 1000.00, 'venda-original');

        $this->assertThrows(
            fn () => $this->vendas->corrigir($this->jose, $venda['venda']->id, [$a1, $a2], 1200.00, ''),
            DomainException::class
        );

        $this->assertSame([$a1, $a2], $venda['venda']->fresh()->animal_ids, 'venda original não pode ter sido alterada pela tentativa de correção recusada');
    }
}
