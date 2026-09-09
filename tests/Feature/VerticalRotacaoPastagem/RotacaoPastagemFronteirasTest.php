<?php

namespace Tests\Feature\VerticalRotacaoPastagem;

use App\Models\Fazenda;
use App\Models\Lote;
use App\Models\Papel;
use App\Models\Piquete;
use App\Models\Usuario;
use App\Services\RotacaoPastagemService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RotacaoPastagemFronteirasTest extends TestCase
{
    use RefreshDatabase;

    private RotacaoPastagemService $rotacao;

    private int $fazenda;

    private int $jose;

    private int $lote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rotacao = app(RotacaoPastagemService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->lote = Lote::create(['fazenda_id' => $this->fazenda, 'qtd_animais' => 20, 'custo_aquisicao' => 40000])->id;
    }

    public function test_chave_idempotencia_vazia_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->rotacao->registrar($this->jose, $this->fazenda, $this->lote, null, null, ['nome' => 'Piquete 1', 'dias_descanso' => 20], '2026-01-01 08:00:00', ''),
            DomainException::class
        );
    }

    public function test_lote_de_outra_fazenda_e_recusado(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'B'])->id;
        $loteDeOutraFazenda = Lote::create(['fazenda_id' => $outraFazenda, 'qtd_animais' => 5, 'custo_aquisicao' => 10000])->id;

        $this->assertThrows(
            fn () => $this->rotacao->registrar($this->jose, $this->fazenda, $loteDeOutraFazenda, null, null, ['nome' => 'Piquete 1', 'dias_descanso' => 20], '2026-01-01 08:00:00', 'x'),
            DomainException::class
        );
    }

    public function test_nem_piquete_destino_nem_piquete_novo_e_recusado(): void
    {
        $this->assertThrows(
            fn () => $this->rotacao->registrar($this->jose, $this->fazenda, $this->lote, null, null, null, '2026-01-01 08:00:00', 'y'),
            DomainException::class
        );
    }

    public function test_piquete_destino_e_piquete_novo_ao_mesmo_tempo_e_recusado(): void
    {
        $piquete = Piquete::create(['fazenda_id' => $this->fazenda, 'nome' => 'Piquete A', 'dias_descanso' => 20])->id;

        $this->assertThrows(
            fn () => $this->rotacao->registrar($this->jose, $this->fazenda, $this->lote, null, $piquete, ['nome' => 'Piquete B', 'dias_descanso' => 20], '2026-01-01 08:00:00', 'z'),
            DomainException::class
        );
    }

    public function test_piquete_novo_com_nome_ja_existente_e_recusado(): void
    {
        Piquete::create(['fazenda_id' => $this->fazenda, 'nome' => 'Piquete A', 'dias_descanso' => 20]);

        $this->assertThrows(
            fn () => $this->rotacao->registrar($this->jose, $this->fazenda, $this->lote, null, null, ['nome' => 'Piquete A', 'dias_descanso' => 15], '2026-01-01 08:00:00', 'w'),
            DomainException::class
        );
    }

    public function test_piquete_de_destino_de_outra_fazenda_e_recusado(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'B'])->id;
        $piqueteDeOutraFazenda = Piquete::create(['fazenda_id' => $outraFazenda, 'nome' => 'Piquete A', 'dias_descanso' => 20])->id;

        $this->assertThrows(
            fn () => $this->rotacao->registrar($this->jose, $this->fazenda, $this->lote, null, $piqueteDeOutraFazenda, null, '2026-01-01 08:00:00', 'v'),
            DomainException::class
        );
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_registra_troca(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;

        $this->assertThrows(
            fn () => $this->rotacao->registrar($mariazinha, $this->fazenda, $this->lote, null, null, ['nome' => 'Piquete 1', 'dias_descanso' => 20], '2026-01-01 08:00:00', 'ataque-mariazinha'),
            DomainException::class
        );
    }
}
