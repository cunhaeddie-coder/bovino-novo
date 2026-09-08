<?php

namespace Tests\Feature\VerticalProducaoLeiteira;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ProducaoLeiteiraService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ProducaoLeiteiraFronteirasTest extends TestCase
{
    use RefreshDatabase;

    private ProducaoLeiteiraService $producoes;

    private int $fazenda;

    private int $jose;

    private int $vaca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->producoes = app(ProducaoLeiteiraService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->vaca = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo'])->id;
    }

    public function test_chave_idempotencia_vazia_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->producoes->registrar($this->jose, $this->fazenda, $this->vaca, '2026-01-01 18:00:00', 10.0, 6.0, 4.0, ''),
            DomainException::class
        );
    }

    public function test_animal_de_outra_fazenda_e_recusado(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'B'])->id;
        $vacaDeOutraFazenda = Animal::create(['fazenda_id' => $outraFazenda, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo'])->id;

        $this->assertThrows(
            fn () => $this->producoes->registrar($this->jose, $this->fazenda, $vacaDeOutraFazenda, '2026-01-01 18:00:00', 10.0, 6.0, 4.0, 'x'),
            DomainException::class
        );
    }

    public function test_quantidade_total_zero_ou_negativa_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->producoes->registrar($this->jose, $this->fazenda, $this->vaca, '2026-01-01 18:00:00', 0, 0, 0, 'y'),
            DomainException::class
        );
    }

    public function test_quantidade_vendida_negativa_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->producoes->registrar($this->jose, $this->fazenda, $this->vaca, '2026-01-01 18:00:00', 10.0, -1, 4.0, 'z'),
            DomainException::class
        );
    }

    public function test_soma_excedendo_total_e_recusada_inv039(): void
    {
        $this->assertThrows(
            fn () => $this->producoes->registrar($this->jose, $this->fazenda, $this->vaca, '2026-01-01 18:00:00', 10.0, 7.0, 5.0, 'w'),
            LogicException::class
        );
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_registra_producao(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;

        $this->assertThrows(
            fn () => $this->producoes->registrar($mariazinha, $this->fazenda, $this->vaca, '2026-01-01 18:00:00', 10.0, 6.0, 4.0, 'ataque-mariazinha'),
            DomainException::class
        );
    }
}
