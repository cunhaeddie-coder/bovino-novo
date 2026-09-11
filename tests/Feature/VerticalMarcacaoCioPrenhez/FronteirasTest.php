<?php

namespace Tests\Feature\VerticalMarcacaoCioPrenhez;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ConfirmacaoPrenhezService;
use App\Services\MarcacaoCioService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FronteirasTest extends TestCase
{
    use RefreshDatabase;

    private MarcacaoCioService $marcacoes;

    private ConfirmacaoPrenhezService $confirmacoes;

    private int $fazenda;

    private int $jose;

    private int $vaca;

    private int $rufiao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marcacoes = app(MarcacaoCioService::class);
        $this->confirmacoes = app(ConfirmacaoPrenhezService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->vaca = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo'])->id;
        $this->rufiao = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 8000, 'status' => 'ativo'])->id;
    }

    public function test_marcacao_cio_chave_vazia_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->marcacoes->registrar($this->jose, $this->fazenda, $this->vaca, $this->rufiao, '2026-01-01 08:00:00', ''),
            DomainException::class
        );
    }

    public function test_marcacao_cio_vaca_de_outra_fazenda_e_recusada(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'B'])->id;
        $vacaDeOutraFazenda = Animal::create(['fazenda_id' => $outraFazenda, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo'])->id;

        $this->assertThrows(
            fn () => $this->marcacoes->registrar($this->jose, $this->fazenda, $vacaDeOutraFazenda, $this->rufiao, '2026-01-01 08:00:00', 'x'),
            DomainException::class
        );
    }

    public function test_marcacao_cio_rufiao_de_outra_fazenda_e_recusado(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'B'])->id;
        $rufiaoDeOutraFazenda = Animal::create(['fazenda_id' => $outraFazenda, 'lote_id' => null, 'custo_aquisicao' => 8000, 'status' => 'ativo'])->id;

        $this->assertThrows(
            fn () => $this->marcacoes->registrar($this->jose, $this->fazenda, $this->vaca, $rufiaoDeOutraFazenda, '2026-01-01 08:00:00', 'y'),
            DomainException::class
        );
    }

    public function test_marcacao_cio_usuario_sem_relacao_com_fazenda_e_recusado(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;

        $this->assertThrows(
            fn () => $this->marcacoes->registrar($mariazinha, $this->fazenda, $this->vaca, $this->rufiao, '2026-01-01 08:00:00', 'ataque-mariazinha'),
            DomainException::class
        );
    }

    public function test_confirmacao_prenhez_chave_vazia_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->confirmacoes->registrar($this->jose, $this->fazenda, $this->vaca, 'positivo', 'ultrassonografia', '2026-01-01 08:00:00', ''),
            DomainException::class
        );
    }

    public function test_confirmacao_prenhez_resultado_invalido_e_recusado(): void
    {
        $this->assertThrows(
            fn () => $this->confirmacoes->registrar($this->jose, $this->fazenda, $this->vaca, 'talvez', 'ultrassonografia', '2026-01-01 08:00:00', 'x'),
            DomainException::class
        );
    }

    public function test_confirmacao_prenhez_tipo_exame_vazio_e_recusado(): void
    {
        $this->assertThrows(
            fn () => $this->confirmacoes->registrar($this->jose, $this->fazenda, $this->vaca, 'positivo', '', '2026-01-01 08:00:00', 'x'),
            DomainException::class
        );
    }

    public function test_confirmacao_prenhez_vaca_de_outra_fazenda_e_recusada(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'B'])->id;
        $vacaDeOutraFazenda = Animal::create(['fazenda_id' => $outraFazenda, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo'])->id;

        $this->assertThrows(
            fn () => $this->confirmacoes->registrar($this->jose, $this->fazenda, $vacaDeOutraFazenda, 'positivo', 'ultrassonografia', '2026-01-01 08:00:00', 'z'),
            DomainException::class
        );
    }

    public function test_confirmacao_prenhez_usuario_sem_relacao_com_fazenda_e_recusado(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;

        $this->assertThrows(
            fn () => $this->confirmacoes->registrar($mariazinha, $this->fazenda, $this->vaca, 'positivo', 'ultrassonografia', '2026-01-01 08:00:00', 'ataque-mariazinha'),
            DomainException::class
        );
    }
}
