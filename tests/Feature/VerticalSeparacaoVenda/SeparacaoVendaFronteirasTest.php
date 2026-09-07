<?php

namespace Tests\Feature\VerticalSeparacaoVenda;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\SeparacaoVendaService;
use App\Services\VendaService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeparacaoVendaFronteirasTest extends TestCase
{
    use RefreshDatabase;

    private SeparacaoVendaService $separacoes;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->separacoes = app(SeparacaoVendaService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
    }

    private function criarAnimal(): int
    {
        return Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id;
    }

    public function test_chave_idempotencia_vazia_e_recusada(): void
    {
        $animal = $this->criarAnimal();

        $this->assertThrows(
            fn () => $this->separacoes->registrar($this->jose, $this->fazenda, [$animal], 1000.00, '2026-01-01 08:00:00', ''),
            DomainException::class
        );
    }

    public function test_separacao_sem_nenhum_animal_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->separacoes->registrar($this->jose, $this->fazenda, [], 1000.00, '2026-01-01 08:00:00', 'separacao-vazia'),
            DomainException::class
        );
    }

    public function test_valor_total_zero_ou_negativo_e_recusado(): void
    {
        $animal = $this->criarAnimal();

        $this->assertThrows(
            fn () => $this->separacoes->registrar($this->jose, $this->fazenda, [$animal], 0, '2026-01-01 08:00:00', 'separacao-y'),
            DomainException::class
        );
    }

    public function test_concluir_separacao_ja_vendida_por_outro_canal_e_recusado(): void
    {
        $animal = $this->criarAnimal();
        $registro = $this->separacoes->registrar($this->jose, $this->fazenda, [$animal], 1000.00, '2026-01-01 08:00:00', 'separacao-z');

        // Vende o mesmo animal por outro canal (Venda direta) antes de concluir a separação.
        app(VendaService::class)->registrar($this->jose, $this->fazenda, [$animal], 500.00, '2026-01-01 09:00:00', 'venda-direta-concorrente');

        $this->assertThrows(
            fn () => $this->separacoes->concluir($this->jose, $registro['separacao']->id),
            DomainException::class
        );
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_registra_separacao(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        $animal = $this->criarAnimal();

        $this->assertThrows(
            fn () => $this->separacoes->registrar($mariazinha, $this->fazenda, [$animal], 1000.00, '2026-01-01 08:00:00', 'ataque-mariazinha'),
            DomainException::class
        );
    }
}
