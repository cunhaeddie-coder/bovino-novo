<?php

namespace Tests\Feature\VerticalRotacaoPastagem;

use App\Models\Fazenda;
use App\Models\Lote;
use App\Models\Piquete;
use App\Models\TrocaPiquete;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer RotacaoPastagemService —
 * SCHEMA-CONTRATO-ROTACAO-PASTAGEM.md. Mesma filosofia dos 14 verticais
 * anteriores: testa que o schema em si (migrations + guards de Model) só
 * permite os estados que o contrato descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    private function criarLote(int $fazendaId): Lote
    {
        return Lote::create(['fazenda_id' => $fazendaId, 'qtd_animais' => 10, 'custo_aquisicao' => 5000]);
    }

    private function criarPiquete(int $fazendaId, string $nome = 'Piquete 1', int $diasDescanso = 20): Piquete
    {
        return Piquete::create(['fazenda_id' => $fazendaId, 'nome' => $nome, 'dias_descanso' => $diasDescanso]);
    }

    public function test_piquete_e_unico_por_fazenda_e_nome(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $this->criarPiquete($fazenda->id, 'Piquete 1');

        $this->assertThrows(
            fn () => $this->criarPiquete($fazenda->id, 'Piquete 1'),
            QueryException::class
        );
    }

    public function test_mesmo_nome_de_piquete_em_fazendas_diferentes_e_permitido(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A']);
        $fazendaB = Fazenda::create(['nome' => 'B']);
        $this->criarPiquete($fazendaA->id, 'Piquete 1');

        $piqueteB = $this->criarPiquete($fazendaB->id, 'Piquete 1');
        $this->assertNotNull($piqueteB->id);
    }

    public function test_troca_piquete_e_imutavel_depois_de_registrada(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $lote = $this->criarLote($fazenda->id);
        $piquete = $this->criarPiquete($fazenda->id);
        $troca = TrocaPiquete::create([
            'fazenda_id' => $fazenda->id, 'lote_id' => $lote->id, 'piquete_origem_id' => null,
            'piquete_destino_id' => $piquete->id, 'data_troca' => '2026-01-01 08:00:00',
            'descanso_interrompido' => false, 'chave_idempotencia' => 'x',
        ]);

        $this->assertThrows(
            fn () => $troca->update(['descanso_interrompido' => true]),
            LogicException::class
        );
    }

    public function test_primeira_alocacao_sem_piquete_de_origem_e_aceita(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $lote = $this->criarLote($fazenda->id);
        $piquete = $this->criarPiquete($fazenda->id);
        $troca = TrocaPiquete::create([
            'fazenda_id' => $fazenda->id, 'lote_id' => $lote->id, 'piquete_origem_id' => null,
            'piquete_destino_id' => $piquete->id, 'data_troca' => '2026-01-01 08:00:00',
            'descanso_interrompido' => false, 'chave_idempotencia' => 'x',
        ]);

        $this->assertNull($troca->piquete_origem_id);
    }

    public function test_troca_piquete_e_unica_por_fazenda_e_chave_idempotencia(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $lote = $this->criarLote($fazenda->id);
        $piquete = $this->criarPiquete($fazenda->id);
        TrocaPiquete::create([
            'fazenda_id' => $fazenda->id, 'lote_id' => $lote->id, 'piquete_origem_id' => null,
            'piquete_destino_id' => $piquete->id, 'data_troca' => '2026-01-01 08:00:00',
            'descanso_interrompido' => false, 'chave_idempotencia' => 'mesma-chave',
        ]);

        $this->assertThrows(
            fn () => TrocaPiquete::create([
                'fazenda_id' => $fazenda->id, 'lote_id' => $lote->id, 'piquete_origem_id' => null,
                'piquete_destino_id' => $piquete->id, 'data_troca' => '2026-01-02 08:00:00',
                'descanso_interrompido' => false, 'chave_idempotencia' => 'mesma-chave',
            ]),
            QueryException::class
        );
    }
}
