<?php

namespace Tests\Feature\VerticalCompraInsumo;

use App\Models\CompraInsumo;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Insumo;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\CompraInsumoService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SCHEMA-CONTRATO-COMPRA-INSUMO.md §12 — trava como regressão permanente:
 * valor_unitario <= 0 e quantidade <= 0 nunca são uma Compra de Insumo
 * válida (é Amostra Grátis, ou nem chega a ser uma linha real), e
 * chave_idempotencia vazia é sempre recusada — mesma regra geral de Compra
 * já fechada em GATE-DECISAO-DOMINIO-COMPRA.md.
 */
class GateDecisaoDominioTest extends TestCase
{
    use RefreshDatabase;

    private CompraInsumoService $compras;

    private int $fazenda;

    private int $jose;

    private int $marilia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compras = app(CompraInsumoService::class);

        $this->fazenda = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->marilia = Fornecedor::create(['nome' => 'Marília'])->id;
    }

    public function test_recusa_valor_unitario_zero(): void
    {
        $this->assertThrows(
            fn () => $this->compras->registrar(
                $this->jose, $this->fazenda, $this->marilia,
                [['insumo_novo' => ['nome' => 'Sal'], 'quantidade' => 10, 'valor_unitario' => 0]],
                '2026-01-01', 'compra-valor-zero'
            ),
            DomainException::class
        );

        $this->assertSame(0, CompraInsumo::count());
        $this->assertSame(0, Insumo::count());
    }

    public function test_recusa_valor_unitario_negativo(): void
    {
        $this->assertThrows(
            fn () => $this->compras->registrar(
                $this->jose, $this->fazenda, $this->marilia,
                [['insumo_novo' => ['nome' => 'Sal'], 'quantidade' => 10, 'valor_unitario' => -5]],
                '2026-01-01', 'compra-valor-negativo'
            ),
            DomainException::class
        );

        $this->assertSame(0, CompraInsumo::count());
        $this->assertSame(0, Insumo::count());
    }

    public function test_recusa_quantidade_zero(): void
    {
        $this->assertThrows(
            fn () => $this->compras->registrar(
                $this->jose, $this->fazenda, $this->marilia,
                [['insumo_novo' => ['nome' => 'Sal'], 'quantidade' => 0, 'valor_unitario' => 17.99]],
                '2026-01-01', 'compra-quantidade-zero'
            ),
            DomainException::class
        );

        $this->assertSame(0, CompraInsumo::count());
        $this->assertSame(0, Insumo::count());
    }

    /** Um item inválido entre válidos ainda recusa a Compra inteira — sem escrita parcial. */
    public function test_compra_com_um_item_invalido_entre_validos_recusa_tudo(): void
    {
        $this->assertThrows(
            fn () => $this->compras->registrar(
                $this->jose, $this->fazenda, $this->marilia,
                [
                    ['insumo_novo' => ['nome' => 'Sal'], 'quantidade' => 80, 'valor_unitario' => 17.99],
                    ['insumo_novo' => ['nome' => 'Fosbovi'], 'quantidade' => 10, 'valor_unitario' => 0],
                ],
                '2026-01-01', 'compra-mista-invalida'
            ),
            DomainException::class
        );

        $this->assertSame(0, CompraInsumo::count());
        $this->assertSame(0, Insumo::count());
    }

    public function test_recusa_chave_idempotencia_vazia(): void
    {
        $this->assertThrows(
            fn () => $this->compras->registrar(
                $this->jose, $this->fazenda, $this->marilia,
                [['insumo_novo' => ['nome' => 'Sal'], 'quantidade' => 10, 'valor_unitario' => 17.99]],
                '2026-01-01', ''
            ),
            DomainException::class
        );

        $this->assertSame(0, CompraInsumo::count());
    }
}
