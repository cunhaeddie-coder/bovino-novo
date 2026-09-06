<?php

namespace Tests\Feature\VerticalConsumoInsumo;

use App\Models\Fazenda;
use App\Models\Insumo;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ConsumoInsumoService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsumoInsumoFronteirasTest extends TestCase
{
    use RefreshDatabase;

    private ConsumoInsumoService $consumos;

    private int $fazenda;

    private int $jose;

    private int $vacina;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumos = app(ConsumoInsumoService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Sítio Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->vacina = Insumo::create(['fazenda_id' => $this->fazenda, 'nome' => 'Vacina Aftosa', 'quantidade' => 100])->id;
    }

    /** INV-035 — consumo nunca deixa o estoque negativo. */
    public function test_consumo_maior_que_o_estoque_e_recusado_inv035(): void
    {
        $this->assertThrows(
            fn () => $this->consumos->registrar($this->jose, $this->fazenda, $this->vacina, 150.00, '2026-01-01 09:00:00', 'consumo-excessivo'),
            DomainException::class
        );

        $this->assertEqualsWithDelta(100.00, (float) Insumo::find($this->vacina)->quantidade, 0.01, 'estoque_intocado_apos_recusa');
    }

    public function test_quantidade_zero_ou_negativa_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->consumos->registrar($this->jose, $this->fazenda, $this->vacina, 0, '2026-01-01 09:00:00', 'consumo-zero'),
            DomainException::class
        );
        $this->assertThrows(
            fn () => $this->consumos->registrar($this->jose, $this->fazenda, $this->vacina, -10, '2026-01-01 09:00:00', 'consumo-negativo'),
            DomainException::class
        );
    }

    public function test_chave_idempotencia_vazia_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->consumos->registrar($this->jose, $this->fazenda, $this->vacina, 10, '2026-01-01 09:00:00', ''),
            DomainException::class
        );
    }

    public function test_insumo_inexistente_e_recusado(): void
    {
        $this->assertThrows(
            fn () => $this->consumos->registrar($this->jose, $this->fazenda, 999999, 10, '2026-01-01 09:00:00', 'x'),
            DomainException::class
        );
    }

    public function test_insumo_de_outra_fazenda_e_recusado(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $insumoDeOutraFazenda = Insumo::create(['fazenda_id' => $outraFazenda, 'nome' => 'Sal', 'quantidade' => 100])->id;

        $this->assertThrows(
            fn () => $this->consumos->registrar($this->jose, $this->fazenda, $insumoDeOutraFazenda, 10, '2026-01-01 09:00:00', 'x'),
            DomainException::class
        );
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_registra_consumo(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;

        $this->assertThrows(
            fn () => $this->consumos->registrar($mariazinha, $this->fazenda, $this->vacina, 10, '2026-01-01 09:00:00', 'ataque-mariazinha'),
            DomainException::class
        );
    }
}
