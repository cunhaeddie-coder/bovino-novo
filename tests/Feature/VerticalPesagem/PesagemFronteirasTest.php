<?php

namespace Tests\Feature\VerticalPesagem;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\PesagemService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PesagemFronteirasTest extends TestCase
{
    use RefreshDatabase;

    private PesagemService $pesagens;

    private int $fazenda;

    private int $jose;

    private int $vaca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pesagens = app(PesagemService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->vaca = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo'])->id;
    }

    public function test_chave_idempotencia_vazia_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->pesagens->registrar($this->jose, $this->fazenda, $this->vaca, 280.0, '2026-01-01 08:00:00', ''),
            DomainException::class
        );
    }

    public function test_animal_de_outra_fazenda_e_recusado(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'B'])->id;
        $vacaDeOutraFazenda = Animal::create(['fazenda_id' => $outraFazenda, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo'])->id;

        $this->assertThrows(
            fn () => $this->pesagens->registrar($this->jose, $this->fazenda, $vacaDeOutraFazenda, 280.0, '2026-01-01 08:00:00', 'x'),
            DomainException::class
        );
    }

    public function test_peso_zero_ou_negativo_e_recusado(): void
    {
        $this->assertThrows(
            fn () => $this->pesagens->registrar($this->jose, $this->fazenda, $this->vaca, 0, '2026-01-01 08:00:00', 'y'),
            DomainException::class
        );
    }

    public function test_lote_vazio_e_recusado(): void
    {
        $this->assertThrows(
            fn () => $this->pesagens->registrarLote($this->jose, $this->fazenda, [], '2026-01-01 08:00:00'),
            DomainException::class
        );
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_registra_pesagem(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;

        $this->assertThrows(
            fn () => $this->pesagens->registrar($mariazinha, $this->fazenda, $this->vaca, 280.0, '2026-01-01 08:00:00', 'ataque-mariazinha'),
            DomainException::class
        );
    }

    /** INV-029 — vaqueiro tem acesso igual ao dono, sem mecanismo novo (achado de LAB-FA-013 satisfeito de graça). */
    public function test_funcionario_com_relacao_com_a_fazenda_tambem_registra_pesagem(): void
    {
        $eddie = Usuario::create(['nome' => 'Eddie'])->id;
        Papel::create(['usuario_id' => $eddie, 'fazenda_id' => $this->fazenda, 'papel' => 'vaqueiro']);

        $registro = $this->pesagens->registrar($eddie, $this->fazenda, $this->vaca, 280.0, '2026-01-01 08:00:00', 'pesagem-vaqueiro');

        $this->assertFalse($registro['reenvio_detectado']);
    }
}
