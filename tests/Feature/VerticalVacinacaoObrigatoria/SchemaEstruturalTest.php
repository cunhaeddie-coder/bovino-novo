<?php

namespace Tests\Feature\VerticalVacinacaoObrigatoria;

use App\Models\ConsumoInsumo;
use App\Models\EventoSaude;
use App\Models\Fazenda;
use App\Models\Insumo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer EventoSaudeService —
 * SCHEMA-CONTRATO-VACINACAO-OBRIGATORIA.md. Mesma filosofia dos 17
 * verticais anteriores: testa que o schema em si (migration) só permite os
 * estados que o contrato descreve — nunca chama um Service. Diferente dos
 * anteriores, este vertical estende uma tabela já existente (eventos_saude,
 * Vertical 11) em vez de criar uma nova.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    private function criarConsumo(int $fazendaId): ConsumoInsumo
    {
        $insumo = Insumo::create(['fazenda_id' => $fazendaId, 'nome' => 'Vacina Aftosa', 'quantidade' => 100]);

        return ConsumoInsumo::create([
            'fazenda_id' => $fazendaId, 'insumo_id' => $insumo->id, 'quantidade' => 10,
            'data_consumo' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'consumo-x',
        ]);
    }

    public function test_certificado_e_tipo_vacina_sao_nullable_evento_saude_comum_continua_funcionando(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $consumo = $this->criarConsumo($fazenda->id);

        $evento = EventoSaude::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1], 'descricao' => 'vermifugo',
            'consumo_insumo_id' => $consumo->id, 'data_aplicacao' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'x',
        ]);

        $this->assertNull($evento->certificado);
        $this->assertNull($evento->tipo_vacina);
    }

    public function test_certificado_e_tipo_vacina_aceitam_valor_quando_e_vacina_fiscalizavel(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $consumo = $this->criarConsumo($fazenda->id);

        $evento = EventoSaude::create([
            'fazenda_id' => $fazenda->id, 'animal_ids' => [1], 'descricao' => 'vacina aftosa',
            'certificado' => 'CERT-2026-001', 'tipo_vacina' => 'aftosa',
            'consumo_insumo_id' => $consumo->id, 'data_aplicacao' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'y',
        ]);

        $this->assertSame('CERT-2026-001', $evento->certificado);
        $this->assertSame('aftosa', $evento->tipo_vacina);
    }
}
