<?php

namespace Tests\Feature\VerticalMarcacaoCioPrenhez;

use App\Models\Animal;
use App\Models\ConfirmacaoPrenhez;
use App\Models\Fazenda;
use App\Models\MarcacaoCio;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Teste do schema, antes de qualquer Service —
 * SCHEMA-CONTRATO-MARCACAO-CIO-PRENHEZ.md. Mesma filosofia dos 16 verticais
 * anteriores: testa que o schema em si (migrations + guards de Model) só
 * permite os estados que o contrato descreve — nunca chama um Service.
 */
class SchemaEstruturalTest extends TestCase
{
    use RefreshDatabase;

    private function criarAnimal(int $fazendaId): Animal
    {
        return Animal::create(['fazenda_id' => $fazendaId, 'lote_id' => null, 'custo_aquisicao' => 5000, 'status' => 'ativo']);
    }

    public function test_marcacao_cio_e_imutavel_depois_de_registrada(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $vaca = $this->criarAnimal($fazenda->id);
        $rufiao = $this->criarAnimal($fazenda->id);
        $marcacao = MarcacaoCio::create([
            'fazenda_id' => $fazenda->id, 'vaca_id' => $vaca->id, 'rufiao_id' => $rufiao->id,
            'data_marcacao' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'x',
        ]);

        $this->assertThrows(fn () => $marcacao->update(['data_marcacao' => '2026-01-02 08:00:00']), LogicException::class);
    }

    public function test_marcacao_cio_e_unica_por_fazenda_e_chave_idempotencia(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $vaca = $this->criarAnimal($fazenda->id);
        $rufiao = $this->criarAnimal($fazenda->id);
        MarcacaoCio::create([
            'fazenda_id' => $fazenda->id, 'vaca_id' => $vaca->id, 'rufiao_id' => $rufiao->id,
            'data_marcacao' => '2026-01-01 08:00:00', 'chave_idempotencia' => 'mesma-chave',
        ]);

        $this->assertThrows(
            fn () => MarcacaoCio::create([
                'fazenda_id' => $fazenda->id, 'vaca_id' => $vaca->id, 'rufiao_id' => $rufiao->id,
                'data_marcacao' => '2026-01-02 08:00:00', 'chave_idempotencia' => 'mesma-chave',
            ]),
            QueryException::class
        );
    }

    public function test_confirmacao_prenhez_e_imutavel_depois_de_registrada(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $vaca = $this->criarAnimal($fazenda->id);
        $confirmacao = ConfirmacaoPrenhez::create([
            'fazenda_id' => $fazenda->id, 'vaca_id' => $vaca->id, 'resultado' => 'positivo',
            'tipo_exame' => 'ultrassonografia', 'data_confirmacao' => '2026-01-01 08:00:00',
            'data_parto_estimada' => '2026-10-11', 'chave_idempotencia' => 'x',
        ]);

        $this->assertThrows(fn () => $confirmacao->update(['resultado' => 'negativo']), LogicException::class);
    }

    public function test_resultado_diferente_de_positivo_negativo_e_recusado(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $vaca = $this->criarAnimal($fazenda->id);

        $this->assertThrows(
            fn () => ConfirmacaoPrenhez::create([
                'fazenda_id' => $fazenda->id, 'vaca_id' => $vaca->id, 'resultado' => 'inconclusivo',
                'tipo_exame' => 'ultrassonografia', 'data_confirmacao' => '2026-01-01 08:00:00',
                'data_parto_estimada' => null, 'chave_idempotencia' => 'x',
            ]),
            LogicException::class
        );
    }

    public function test_resultado_negativo_com_data_parto_estimada_e_recusado(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $vaca = $this->criarAnimal($fazenda->id);

        $this->assertThrows(
            fn () => ConfirmacaoPrenhez::create([
                'fazenda_id' => $fazenda->id, 'vaca_id' => $vaca->id, 'resultado' => 'negativo',
                'tipo_exame' => 'ultrassonografia', 'data_confirmacao' => '2026-01-01 08:00:00',
                'data_parto_estimada' => '2026-10-11', 'chave_idempotencia' => 'x',
            ]),
            LogicException::class
        );
    }

    public function test_resultado_positivo_sem_data_parto_estimada_e_recusado(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $vaca = $this->criarAnimal($fazenda->id);

        $this->assertThrows(
            fn () => ConfirmacaoPrenhez::create([
                'fazenda_id' => $fazenda->id, 'vaca_id' => $vaca->id, 'resultado' => 'positivo',
                'tipo_exame' => 'ultrassonografia', 'data_confirmacao' => '2026-01-01 08:00:00',
                'data_parto_estimada' => null, 'chave_idempotencia' => 'x',
            ]),
            LogicException::class
        );
    }

    public function test_confirmacao_prenhez_e_unica_por_fazenda_e_chave_idempotencia(): void
    {
        $fazenda = Fazenda::create(['nome' => 'A']);
        $vaca = $this->criarAnimal($fazenda->id);
        ConfirmacaoPrenhez::create([
            'fazenda_id' => $fazenda->id, 'vaca_id' => $vaca->id, 'resultado' => 'negativo',
            'tipo_exame' => 'ultrassonografia', 'data_confirmacao' => '2026-01-01 08:00:00',
            'data_parto_estimada' => null, 'chave_idempotencia' => 'mesma-chave',
        ]);

        $this->assertThrows(
            fn () => ConfirmacaoPrenhez::create([
                'fazenda_id' => $fazenda->id, 'vaca_id' => $vaca->id, 'resultado' => 'negativo',
                'tipo_exame' => 'ultrassonografia', 'data_confirmacao' => '2026-02-01 08:00:00',
                'data_parto_estimada' => null, 'chave_idempotencia' => 'mesma-chave',
            ]),
            QueryException::class
        );
    }
}
