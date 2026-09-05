<?php

namespace Tests\Feature\VerticalVenda;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Lote;
use App\Models\ObservabilidadeVarredura;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\OutboxService;
use App\Services\VendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * O teste operacional obrigatório de OBSERVABILIDADE-MINIMA-VENDA.md §4 —
 * três fases contra código real, tempo real (sleep real, não relógio
 * mockado), mesmo critério do Ataque 0 do Spike 007. Não declara
 * observabilidade "pronta" sem isto: prova que os sinais denunciam a
 * situação real, não só que existem.
 */
class ObservabilidadeOperacionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_fase_1_estado_normal_sinais_refletem_ausencia_de_trabalho(): void
    {
        Artisan::call('eventos:processar');
        $status = app(OutboxService::class)->status();

        $this->assertSame(0, $status['outbox_pending']);
        $this->assertNull($status['outbox_oldest_pending_age_seconds']);
        $this->assertNotNull($status['scanner_last_run_at'], 'a varredura rodou, o sinal precisa refletir isso');
    }

    public function test_fase_2_ataque_processador_parado_sinais_denunciam(): void
    {
        // A varredura roda uma vez (estado normal), depois PARA de rodar —
        // simula o processador morto. O `go` deliberado é NUNCA rodar
        // eventos:processar de novo depois deste ponto.
        Artisan::call('eventos:processar');
        $scannerAntesDoAtaque = ObservabilidadeVarredura::ultimaExecucao();

        [$fazenda, $usuario, $animal] = $this->semearMundoMinimo();

        // Cria trabalho pendente real — uma Venda de verdade, evento outbox real.
        app(VendaService::class)->registrar($usuario, $fazenda, [$animal], 500.00, '2026-01-01 10:00:00', 'obs-ataque-1');

        // Tempo real passa. Sleep curto mas real — nada de Carbon::setTestNow().
        sleep(2);

        $status = app(OutboxService::class)->status();

        $this->assertSame(1, $status['outbox_pending'], 'o evento criado pela Venda real precisa aparecer como pendente');
        $this->assertGreaterThanOrEqual(2, $status['outbox_oldest_pending_age_seconds'], 'o evento precisa estar envelhecendo de verdade');
        $this->assertEquals(
            $scannerAntesDoAtaque->toDateTimeString(),
            ObservabilidadeVarredura::ultimaExecucao()->toDateTimeString(),
            'scanner_last_run_at NÃO pode avançar sozinho — é este sinal, não outbox_pending, que prova que o processador morreu (§1, pergunta 7)'
        );
    }

    public function test_fase_3_recuperacao_processador_religado_sinais_voltam_ao_normal(): void
    {
        Artisan::call('eventos:processar');
        [$fazenda, $usuario, $animal] = $this->semearMundoMinimo();
        app(VendaService::class)->registrar($usuario, $fazenda, [$animal], 500.00, '2026-01-01 10:00:00', 'obs-recuperacao-1');
        sleep(2);

        // Confirma que o "ataque" realmente deixou rastro antes de religar.
        $this->assertSame(1, app(OutboxService::class)->status()['outbox_pending']);

        // Religa o processador — mesmo comando real, nada simulado.
        Artisan::call('eventos:processar');

        $status = app(OutboxService::class)->status();

        $this->assertSame(0, $status['outbox_pending'], 'o evento pendente precisa ter sido processado');
        $this->assertSame(1, $status['outbox_processed_total']);
        $this->assertNotNull($status['outbox_last_success_at']);
        $this->assertNotNull($status['scanner_last_run_at']);
    }

    /** @return array{0:int,1:int,2:int} [fazendaId, usuarioId, animalId] */
    private function semearMundoMinimo(): array
    {
        $fazenda = Fazenda::create(['nome' => 'Observabilidade'])->id;
        $usuario = Usuario::create(['nome' => 'U'])->id;
        Papel::create(['usuario_id' => $usuario, 'fazenda_id' => $fazenda, 'papel' => 'dono']);
        $lote = Lote::create(['fazenda_id' => $fazenda, 'qtd_animais' => 1, 'custo_aquisicao' => 100])->id;
        $animal = Animal::create(['fazenda_id' => $fazenda, 'lote_id' => $lote, 'status' => 'ativo'])->id;

        return [$fazenda, $usuario, $animal];
    }
}
