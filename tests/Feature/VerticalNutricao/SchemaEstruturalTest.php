<?php

namespace Tests\Feature\VerticalNutricao;

use App\Models\ConsumoInsumo;
use App\Models\EventoSaude;
use App\Models\Fazenda;
use App\Models\Insumo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer EventoSaudeService —
 * SCHEMA-CONTRATO-NUTRICAO.md. Mesma filosofia dos 18 verticais anteriores:
 * testa que o schema em si (migration) só permite os estados que o contrato
 * descreve — nunca chama um Service. Segunda vez (depois do Vertical 18)
 * que um vertical estende eventos_saude em vez de criar tabela nova.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    private function criarConsumo(int $fazendaId): ConsumoInsumo
    {
        $insumo = Insumo::create(['fazenda_id' => $fazendaId, 'nome' => 'Sal Proteinado 30kg', 'quantidade' => 100]);

        return ConsumoInsumo::create([
            'fazenda_id' => $fazendaId, 'insumo_id' => $insumo->id, 'quantidade' => 10,
            'data_consumo' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'consumo-x',
        ]);
    }

    public function test_epoca_e_nullable_evento_saude_comum_continua_funcionando(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $consumo = $this->criarConsumo($fazenda->id);

        $evento = EventoSaude::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1], 'descricao' => 'vermifugo',
            'consumo_insumo_id' => $consumo->id, 'data_aplicacao' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'x',
        ]);

        $this->assertNull($evento->epoca);
    }

    public function test_epoca_aceita_valor_quando_e_plano_nutricional(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $consumo = $this->criarConsumo($fazenda->id);

        $evento = EventoSaude::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1], 'descricao' => 'sal proteinado',
            'epoca' => 'seca', 'consumo_insumo_id' => $consumo->id,
            'data_aplicacao' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'y',
        ]);

        $this->assertSame('seca', $evento->epoca);
    }

    /** epoca convive com certificado/tipo_vacina do Vertical 18 sem colisão — campos independentes. */
    public function test_epoca_convive_com_certificado_tipo_vacina_do_vertical_18(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $consumo = $this->criarConsumo($fazenda->id);

        $evento = EventoSaude::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1], 'descricao' => 'vacina aftosa',
            'certificado' => 'CERT-2026-001', 'tipo_vacina' => 'aftosa', 'epoca' => 'aguas',
            'consumo_insumo_id' => $consumo->id, 'data_aplicacao' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'z',
        ]);

        $this->assertSame('CERT-2026-001', $evento->certificado);
        $this->assertSame('aftosa', $evento->tipo_vacina);
        $this->assertSame('aguas', $evento->epoca);
    }
}
