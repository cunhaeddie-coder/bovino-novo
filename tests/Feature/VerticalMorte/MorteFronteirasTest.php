<?php

namespace Tests\Feature\VerticalMorte;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\MorteService;
use App\Services\VendaService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MorteFronteirasTest extends TestCase
{
    use RefreshDatabase;

    private MorteService $mortes;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mortes = app(MorteService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
    }

    private function criarAnimal(): int
    {
        return Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id;
    }

    /** INV-038 — causa sempre obrigatória. */
    public function test_causa_vazia_e_recusada(): void
    {
        $animal = $this->criarAnimal();

        $this->assertThrows(
            fn () => $this->mortes->registrar($this->jose, $this->fazenda, [$animal], '', '2026-01-01 08:00:00', 'morte-x'),
            DomainException::class
        );
        $this->assertSame('ativo', Animal::find($animal)->status, 'animal_nao_afetado_por_morte_recusada');
    }

    public function test_causa_so_espacos_e_recusada(): void
    {
        $animal = $this->criarAnimal();

        $this->assertThrows(
            fn () => $this->mortes->registrar($this->jose, $this->fazenda, [$animal], '   ', '2026-01-01 08:00:00', 'morte-y'),
            DomainException::class
        );
    }

    public function test_chave_idempotencia_vazia_e_recusada(): void
    {
        $animal = $this->criarAnimal();

        $this->assertThrows(
            fn () => $this->mortes->registrar($this->jose, $this->fazenda, [$animal], 'doença', '2026-01-01 08:00:00', ''),
            DomainException::class
        );
    }

    public function test_morte_sem_nenhum_animal_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->mortes->registrar($this->jose, $this->fazenda, [], 'doença', '2026-01-01 08:00:00', 'morte-vazia'),
            DomainException::class
        );
    }

    public function test_animal_ja_morto_nao_pode_morrer_de_novo(): void
    {
        $animal = $this->criarAnimal();
        $this->mortes->registrar($this->jose, $this->fazenda, [$animal], 'doença', '2026-01-01 08:00:00', 'morte-1');

        $this->assertThrows(
            fn () => $this->mortes->registrar($this->jose, $this->fazenda, [$animal], 'acidente', '2026-01-02 08:00:00', 'morte-2'),
            DomainException::class
        );
    }

    public function test_animal_ja_vendido_nao_pode_morrer(): void
    {
        $animal = $this->criarAnimal();
        app(VendaService::class)->registrar($this->jose, $this->fazenda, [$animal], 500.00, '2026-01-01 08:00:00', 'venda-1');

        $this->assertThrows(
            fn () => $this->mortes->registrar($this->jose, $this->fazenda, [$animal], 'doença', '2026-01-02 08:00:00', 'morte-apos-venda'),
            DomainException::class
        );
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_registra_morte(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        $animal = $this->criarAnimal();

        $this->assertThrows(
            fn () => $this->mortes->registrar($mariazinha, $this->fazenda, [$animal], 'doença', '2026-01-01 08:00:00', 'ataque-mariazinha'),
            DomainException::class
        );
    }
}
