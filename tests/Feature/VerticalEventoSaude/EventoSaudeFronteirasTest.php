<?php

namespace Tests\Feature\VerticalEventoSaude;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Insumo;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\EventoSaudeService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventoSaudeFronteirasTest extends TestCase
{
    use RefreshDatabase;

    private EventoSaudeService $eventos;

    private int $fazenda;

    private int $jose;

    private int $insumo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventos = app(EventoSaudeService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->insumo = Insumo::create(['fazenda_id' => $this->fazenda, 'nome' => 'Vacina Aftosa', 'quantidade' => 100])->id;
    }

    private function criarAnimal(): int
    {
        return Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id;
    }

    public function test_chave_idempotencia_vazia_e_recusada(): void
    {
        $animal = $this->criarAnimal();

        $this->assertThrows(
            fn () => $this->eventos->registrar($this->jose, $this->fazenda, [$animal], $this->insumo, 5.0, 'vacina', '2026-01-01 08:00:00', ''),
            DomainException::class
        );
    }

    public function test_evento_sem_nenhum_animal_e_recusado(): void
    {
        $this->assertThrows(
            fn () => $this->eventos->registrar($this->jose, $this->fazenda, [], $this->insumo, 5.0, 'vacina', '2026-01-01 08:00:00', 'x'),
            DomainException::class
        );
    }

    public function test_descricao_vazia_e_recusada(): void
    {
        $animal = $this->criarAnimal();

        $this->assertThrows(
            fn () => $this->eventos->registrar($this->jose, $this->fazenda, [$animal], $this->insumo, 5.0, '', '2026-01-01 08:00:00', 'y'),
            DomainException::class
        );
    }

    public function test_animal_de_outra_fazenda_e_recusado(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'B'])->id;
        $animalDeOutraFazenda = Animal::create(['fazenda_id' => $outraFazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id;

        $this->assertThrows(
            fn () => $this->eventos->registrar($this->jose, $this->fazenda, [$animalDeOutraFazenda], $this->insumo, 5.0, 'vacina', '2026-01-01 08:00:00', 'z'),
            DomainException::class
        );
    }

    public function test_consumo_que_excede_estoque_e_recusado_herdado_de_consumo_insumo(): void
    {
        $animal = $this->criarAnimal();

        $this->assertThrows(
            fn () => $this->eventos->registrar($this->jose, $this->fazenda, [$animal], $this->insumo, 999.0, 'vacina', '2026-01-01 08:00:00', 'w'),
            DomainException::class
        );
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_registra_evento(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        $animal = $this->criarAnimal();

        $this->assertThrows(
            fn () => $this->eventos->registrar($mariazinha, $this->fazenda, [$animal], $this->insumo, 5.0, 'vacina', '2026-01-01 08:00:00', 'ataque-mariazinha'),
            DomainException::class
        );
    }
}
