<?php

namespace Tests\Feature\VerticalProtocoloReprodutivo;

use App\Models\EtapaProtocolo;
use App\Models\Fazenda;
use App\Models\ProtocoloReprodutivo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer ProtocoloReprodutivoService —
 * SCHEMA-CONTRATO-PROTOCOLO-REPRODUTIVO.md. Mesma filosofia dos 12
 * verticais anteriores: testa que o schema em si (migrations + guards de
 * Model) só permite os estados que o contrato descreve — nunca chama um
 * Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    private function criarProtocolo(int $fazendaId): ProtocoloReprodutivo
    {
        return ProtocoloReprodutivo::create([
            'fazenda_id' => $fazendaId, 'animal_ids' => [1, 2], 'data_inicio' => '2026-01-01 08:00:00',
            'status' => 'em_andamento', 'chave_idempotencia' => 'x',
        ]);
    }

    public function test_protocolo_concluido_e_terminal(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $protocolo = $this->criarProtocolo($fazenda->id);
        $protocolo->update(['status' => 'concluido']);

        $this->assertThrows(
            fn () => $protocolo->update(['status' => 'em_andamento']),
            LogicException::class
        );
    }

    public function test_protocolo_em_andamento_aceita_transicao_para_concluido(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $protocolo = $this->criarProtocolo($fazenda->id);

        $protocolo->update(['status' => 'concluido']);
        $this->assertSame('concluido', $protocolo->fresh()->status);
    }

    public function test_protocolo_e_unico_por_fazenda_e_chave_idempotencia(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $this->criarProtocolo($fazenda->id);

        $this->assertThrows(
            fn () => ProtocoloReprodutivo::create([
                'fazenda_id' => $fazenda->id, 'animal_ids' => [3], 'data_inicio' => '2026-02-01 08:00:00',
                'status' => 'em_andamento', 'chave_idempotencia' => 'x',
            ]),
            QueryException::class
        );
    }

    public function test_etapa_exige_tipo_do_vocabulario_fechado(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $protocolo = $this->criarProtocolo($fazenda->id);

        $this->assertThrows(
            fn () => EtapaProtocolo::create([
                'fazenda_id' => $fazenda->id, 'protocolo_reprodutivo_id' => $protocolo->id,
                'tipo' => 'vacina', 'data_prevista' => '2026-01-01 08:00:00',
            ]),
            LogicException::class
        );
    }

    public function test_etapa_ja_cumprida_e_terminal(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $protocolo = $this->criarProtocolo($fazenda->id);
        $etapa = EtapaProtocolo::create([
            'fazenda_id' => $fazenda->id, 'protocolo_reprodutivo_id' => $protocolo->id,
            'tipo' => 'implante', 'data_prevista' => '2026-01-01 08:00:00', 'data_realizada' => '2026-01-01 08:00:00',
        ]);

        $this->assertThrows(
            fn () => $etapa->update(['data_realizada' => '2026-01-02 08:00:00']),
            LogicException::class
        );
    }

    public function test_etapa_e_unica_por_protocolo_e_tipo(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $protocolo = $this->criarProtocolo($fazenda->id);
        EtapaProtocolo::create([
            'fazenda_id' => $fazenda->id, 'protocolo_reprodutivo_id' => $protocolo->id,
            'tipo' => 'implante', 'data_prevista' => '2026-01-01 08:00:00',
        ]);

        $this->assertThrows(
            fn () => EtapaProtocolo::create([
                'fazenda_id' => $fazenda->id, 'protocolo_reprodutivo_id' => $protocolo->id,
                'tipo' => 'implante', 'data_prevista' => '2026-01-01 08:00:00',
            ]),
            QueryException::class
        );
    }
}
