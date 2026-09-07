<?php

namespace Tests\Feature\VerticalFolhaPagamento;

use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\FuncionarioService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class FolhaPagamentoFronteirasTest extends TestCase
{
    use RefreshDatabase;

    private FuncionarioService $funcionarios;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->funcionarios = app(FuncionarioService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
    }

    public function test_chave_idempotencia_vazia_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->funcionarios->contratar($this->jose, $this->fazenda, 'Eddie', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', ''),
            DomainException::class
        );
    }

    public function test_nome_vazio_e_recusado(): void
    {
        $this->assertThrows(
            fn () => $this->funcionarios->contratar($this->jose, $this->fazenda, '', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', 'x'),
            DomainException::class
        );
    }

    public function test_salario_zero_ou_negativo_e_recusado(): void
    {
        $this->assertThrows(
            fn () => $this->funcionarios->contratar($this->jose, $this->fazenda, 'Eddie', 'vaqueiro', 0, '2026-01-01 08:00:00', 'y'),
            LogicException::class
        );
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_contrata(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;

        $this->assertThrows(
            fn () => $this->funcionarios->contratar($mariazinha, $this->fazenda, 'Eddie', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', 'ataque-mariazinha'),
            DomainException::class
        );
    }

    public function test_reenvio_da_contratacao_e_idempotente(): void
    {
        $primeiro = $this->funcionarios->contratar($this->jose, $this->fazenda, 'Eddie', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', 'mesma-chave');
        $segundo = $this->funcionarios->contratar($this->jose, $this->fazenda, 'Eddie', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', 'mesma-chave');

        $this->assertTrue($segundo['reenvio_detectado']);
        $this->assertSame($primeiro['funcionario']->id, $segundo['funcionario']->id);
    }

    public function test_desligar_duas_vezes_e_idempotente(): void
    {
        $registro = $this->funcionarios->contratar($this->jose, $this->fazenda, 'Eddie', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', 'x');

        $this->funcionarios->desligar($this->jose, $registro['funcionario']->id, '2026-02-01 08:00:00');
        $segundoDesligamento = $this->funcionarios->desligar($this->jose, $registro['funcionario']->id, '2026-02-05 08:00:00');

        $this->assertSame('desligado', $segundoDesligamento->status);
    }
}
