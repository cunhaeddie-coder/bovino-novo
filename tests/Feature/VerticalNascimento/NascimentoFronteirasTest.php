<?php

namespace Tests\Feature\VerticalNascimento;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\NascimentoService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NascimentoFronteirasTest extends TestCase
{
    use RefreshDatabase;

    private NascimentoService $nascimentos;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nascimentos = app(NascimentoService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
    }

    public function test_chave_idempotencia_vazia_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->nascimentos->registrar($this->jose, $this->fazenda, [[]], '2026-01-01 08:00:00', ''),
            DomainException::class
        );
    }

    public function test_nascimento_sem_nenhum_filhote_e_recusado(): void
    {
        $this->assertThrows(
            fn () => $this->nascimentos->registrar($this->jose, $this->fazenda, [], '2026-01-01 08:00:00', 'nascimento-vazio'),
            DomainException::class
        );
    }

    public function test_mae_de_outra_fazenda_e_recusada(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'B'])->id;
        $maeDeOutraFazenda = Animal::create(['fazenda_id' => $outraFazenda, 'lote_id' => null, 'custo_aquisicao' => 1000, 'status' => 'ativo'])->id;

        $this->assertThrows(
            fn () => $this->nascimentos->registrar($this->jose, $this->fazenda, [['mae_id' => $maeDeOutraFazenda]], '2026-01-01 08:00:00', 'nascimento-x'),
            DomainException::class
        );
    }

    public function test_mae_inexistente_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->nascimentos->registrar($this->jose, $this->fazenda, [['mae_id' => 999999]], '2026-01-01 08:00:00', 'nascimento-y'),
            DomainException::class
        );
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_registra_nascimento(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;

        $this->assertThrows(
            fn () => $this->nascimentos->registrar($mariazinha, $this->fazenda, [[]], '2026-01-01 08:00:00', 'ataque-mariazinha'),
            DomainException::class
        );
    }
}
