<?php

namespace Tests\Feature\VerticalArrendamento;

use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ArrendamentoService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArrendamentoFronteirasTest extends TestCase
{
    use RefreshDatabase;

    private ArrendamentoService $arrendamentos;

    private int $fazenda;

    private int $jose;

    private int $fornecedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrendamentos = app(ArrendamentoService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->fornecedor = Fornecedor::create(['nome' => 'Zé da Terra'])->id;
    }

    public function test_chave_idempotencia_vazia_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->arrendamentos->registrar($this->jose, $this->fazenda, $this->fornecedor, 1000.00, 'mensal', '2026-01-01 08:00:00', '2026-06-01 08:00:00', ''),
            DomainException::class
        );
    }

    public function test_fornecedor_inexistente_e_recusado(): void
    {
        $this->assertThrows(
            fn () => $this->arrendamentos->registrar($this->jose, $this->fazenda, 999999, 1000.00, 'mensal', '2026-01-01 08:00:00', '2026-06-01 08:00:00', 'x'),
            DomainException::class
        );
    }

    public function test_valor_total_zero_ou_negativo_e_recusado(): void
    {
        $this->assertThrows(
            fn () => $this->arrendamentos->registrar($this->jose, $this->fazenda, $this->fornecedor, 0, 'mensal', '2026-01-01 08:00:00', '2026-06-01 08:00:00', 'y'),
            DomainException::class
        );
    }

    public function test_periodicidade_invalida_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->arrendamentos->registrar($this->jose, $this->fazenda, $this->fornecedor, 1000.00, 'semanal', '2026-01-01 08:00:00', '2026-06-01 08:00:00', 'z'),
            DomainException::class
        );
    }

    public function test_data_fim_anterior_ou_igual_a_data_inicio_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->arrendamentos->registrar($this->jose, $this->fazenda, $this->fornecedor, 1000.00, 'mensal', '2026-06-01 08:00:00', '2026-01-01 08:00:00', 'w'),
            DomainException::class
        );
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_registra_arrendamento(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;

        $this->assertThrows(
            fn () => $this->arrendamentos->registrar($mariazinha, $this->fazenda, $this->fornecedor, 1000.00, 'mensal', '2026-01-01 08:00:00', '2026-06-01 08:00:00', 'ataque-mariazinha'),
            DomainException::class
        );
    }

    public function test_gerar_parcela_alem_do_total_e_recusado(): void
    {
        $registro = $this->arrendamentos->registrar($this->jose, $this->fazenda, $this->fornecedor, 1000.00, 'mensal', '2026-01-01 08:00:00', '2026-03-01 08:00:00', 'v');

        $this->arrendamentos->gerarProximaParcela($this->jose, $registro['arrendamento']->id);
        $this->arrendamentos->gerarProximaParcela($this->jose, $registro['arrendamento']->id);

        $this->assertThrows(
            fn () => $this->arrendamentos->gerarProximaParcela($this->jose, $registro['arrendamento']->id),
            DomainException::class
        );
    }
}
