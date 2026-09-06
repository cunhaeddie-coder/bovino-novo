<?php

namespace Tests\Feature\VerticalGta;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\GtaService;
use App\Services\VendaService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GtaFronteirasTest extends TestCase
{
    use RefreshDatabase;

    private GtaService $gtas;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gtas = app(GtaService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
    }

    private function criarAnimal(): int
    {
        return Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id;
    }

    /** INV-036 — quantidade_declarada precisa bater com animal_ids. */
    public function test_quantidade_declarada_divergente_e_recusada(): void
    {
        $animal = $this->criarAnimal();

        $this->assertThrows(
            fn () => $this->gtas->registrar($this->jose, $this->fazenda, [$animal], 'X', 2, 1000.00, '2026-01-01 08:00:00', 'gta-x'),
            \LogicException::class
        );
    }

    public function test_chave_idempotencia_vazia_e_recusada(): void
    {
        $animal = $this->criarAnimal();

        $this->assertThrows(
            fn () => $this->gtas->registrar($this->jose, $this->fazenda, [$animal], 'X', 1, 1000.00, '2026-01-01 08:00:00', ''),
            DomainException::class
        );
    }

    public function test_gta_sem_nenhum_animal_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->gtas->registrar($this->jose, $this->fazenda, [], 'X', 0, 1000.00, '2026-01-01 08:00:00', 'gta-vazia'),
            DomainException::class
        );
    }

    public function test_valor_bruto_zero_ou_negativo_e_recusado(): void
    {
        $animal = $this->criarAnimal();

        $this->assertThrows(
            fn () => $this->gtas->registrar($this->jose, $this->fazenda, [$animal], 'X', 1, 0, '2026-01-01 08:00:00', 'gta-y'),
            DomainException::class
        );
    }

    public function test_concluir_gta_ja_vendida_por_outro_canal_e_recusado(): void
    {
        $animal = $this->criarAnimal();
        $registro = $this->gtas->registrar($this->jose, $this->fazenda, [$animal], 'X', 1, 1000.00, '2026-01-01 08:00:00', 'gta-z');

        // Vende o mesmo animal por outro canal (Venda direta) antes de concluir a GTA.
        app(VendaService::class)->registrar($this->jose, $this->fazenda, [$animal], 500.00, '2026-01-01 09:00:00', 'venda-direta-concorrente');

        $this->assertThrows(
            fn () => $this->gtas->concluir($this->jose, $registro['gta']->id),
            DomainException::class
        );
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_registra_gta(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        $animal = $this->criarAnimal();

        $this->assertThrows(
            fn () => $this->gtas->registrar($mariazinha, $this->fazenda, [$animal], 'X', 1, 1000.00, '2026-01-01 08:00:00', 'ataque-mariazinha'),
            DomainException::class
        );
    }
}
