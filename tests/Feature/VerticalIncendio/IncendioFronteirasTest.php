<?php

namespace Tests\Feature\VerticalIncendio;

use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Piquete;
use App\Models\Usuario;
use App\Services\IncendioService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncendioFronteirasTest extends TestCase
{
    use RefreshDatabase;

    private IncendioService $incendios;

    private int $fazenda;

    private int $jose;

    private int $piquete;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incendios = app(IncendioService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->piquete = Piquete::create(['fazenda_id' => $this->fazenda, 'nome' => 'Piquete 1', 'dias_descanso' => 20])->id;
    }

    public function test_chave_idempotencia_vazia_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->incendios->registrar($this->jose, $this->fazenda, $this->piquete, '2026-01-01 14:00:00', ''),
            DomainException::class
        );
    }

    public function test_piquete_de_outra_fazenda_e_recusado(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'B'])->id;
        $piqueteDeOutraFazenda = Piquete::create(['fazenda_id' => $outraFazenda, 'nome' => 'Piquete X', 'dias_descanso' => 20])->id;

        $this->assertThrows(
            fn () => $this->incendios->registrar($this->jose, $this->fazenda, $piqueteDeOutraFazenda, '2026-01-01 14:00:00', 'x'),
            DomainException::class
        );
    }

    public function test_piquete_inexistente_e_recusado(): void
    {
        $this->assertThrows(
            fn () => $this->incendios->registrar($this->jose, $this->fazenda, 999999, '2026-01-01 14:00:00', 'y'),
            DomainException::class
        );
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_registra_incendio(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;

        $this->assertThrows(
            fn () => $this->incendios->registrar($mariazinha, $this->fazenda, $this->piquete, '2026-01-01 14:00:00', 'ataque-mariazinha'),
            DomainException::class
        );
    }
}
