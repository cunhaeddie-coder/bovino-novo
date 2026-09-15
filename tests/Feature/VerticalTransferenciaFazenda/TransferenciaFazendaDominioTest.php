<?php

namespace Tests\Feature\VerticalTransferenciaFazenda;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Lote;
use App\Models\Papel;
use App\Models\Titular;
use App\Models\TransferenciaFazenda;
use App\Models\Usuario;
use App\Services\TransferenciaFazendaService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 26 (Transferência entre Fazendas) — nasce de
 * VERTICAL-TRANSFERENCIA-FAZENDA.md e SCHEMA-CONTRATO-TRANSFERENCIA-
 * FAZENDA.md. Formaliza em código a exceção nova de INV-022: mover Animal
 * entre Fazendas do mesmo Titular é reorganização interna, sem evento
 * comercial formal.
 */
class TransferenciaFazendaDominioTest extends TestCase
{
    use RefreshDatabase;

    private TransferenciaFazendaService $service;

    private int $usuarioId;

    private int $fazendaOrigemId;

    private int $fazendaDestinoId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TransferenciaFazendaService::class);

        $titular = Titular::create(['documento' => '11222333000181', 'tipo_documento' => 'cnpj']);
        $this->fazendaOrigemId = Fazenda::create(['nome' => 'Fazenda Origem', 'titular_id' => $titular->id])->id;
        $this->fazendaDestinoId = Fazenda::create(['nome' => 'Fazenda Destino', 'titular_id' => $titular->id])->id;

        $this->usuarioId = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->usuarioId, 'fazenda_id' => $this->fazendaOrigemId, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $this->usuarioId, 'fazenda_id' => $this->fazendaDestinoId, 'papel' => 'dono']);
    }

    private function criarAnimalAtivo(int $fazendaId, float $custo = 1000.0, string $raca = 'Nelore'): Animal
    {
        return Animal::create(['fazenda_id' => $fazendaId, 'custo_aquisicao' => $custo, 'status' => 'ativo', 'raca' => $raca]);
    }

    public function test_transferir_move_animal_carregando_custo_aquisicao(): void
    {
        $animal = $this->criarAnimalAtivo($this->fazendaOrigemId, 1500.0, 'Angus');

        $resultado = $this->service->transferir($this->usuarioId, $this->fazendaOrigemId, $this->fazendaDestinoId, [$animal->id], 'chave-1');

        $this->assertFalse($resultado['reenvio_detectado']);
        $animalDestino = $resultado['animais_destino'][0];
        $this->assertSame($this->fazendaDestinoId, $animalDestino->fazenda_id);
        $this->assertSame('ativo', $animalDestino->status);
        $this->assertEquals(1500.0, $animalDestino->custo_aquisicao);
        $this->assertSame('Angus', $animalDestino->raca);
    }

    public function test_animal_de_origem_vira_status_transferido(): void
    {
        $animal = $this->criarAnimalAtivo($this->fazendaOrigemId);

        $this->service->transferir($this->usuarioId, $this->fazendaOrigemId, $this->fazendaDestinoId, [$animal->id], 'chave-1');

        $this->assertSame('transferido', $animal->fresh()->status);
        $this->assertNotNull($animal->fresh()->data_saida);
    }

    public function test_categoria_finalidade_e_mae_id_nao_sao_herdados_no_destino(): void
    {
        $animal = Animal::create([
            'fazenda_id' => $this->fazendaOrigemId,
            'custo_aquisicao' => 1000,
            'status' => 'ativo',
            'categoria' => 'novilha',
            'finalidade' => 'corte',
        ]);

        $resultado = $this->service->transferir($this->usuarioId, $this->fazendaOrigemId, $this->fazendaDestinoId, [$animal->id], 'chave-1');

        $animalDestino = $resultado['animais_destino'][0];
        $this->assertNull($animalDestino->categoria);
        $this->assertNull($animalDestino->finalidade);
        $this->assertNull($animalDestino->mae_id);
    }

    public function test_reenvio_com_mesma_chave_nao_reprocessa(): void
    {
        $animal = $this->criarAnimalAtivo($this->fazendaOrigemId);

        $this->service->transferir($this->usuarioId, $this->fazendaOrigemId, $this->fazendaDestinoId, [$animal->id], 'chave-1');
        $segunda = $this->service->transferir($this->usuarioId, $this->fazendaOrigemId, $this->fazendaDestinoId, [$animal->id], 'chave-1');

        $this->assertTrue($segunda['reenvio_detectado']);
        $this->assertSame(2, Animal::count()); // origem (transferido) + destino, nunca um terceiro
    }

    /** INV-050 — sem Titular vinculado, exige evento comercial formal. */
    public function test_fazendas_sem_titular_recusa_transferencia(): void
    {
        $fazendaSemTitularA = Fazenda::create(['nome' => 'Sem Titular A']);
        $fazendaSemTitularB = Fazenda::create(['nome' => 'Sem Titular B']);
        Papel::create(['usuario_id' => $this->usuarioId, 'fazenda_id' => $fazendaSemTitularA->id, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $this->usuarioId, 'fazenda_id' => $fazendaSemTitularB->id, 'papel' => 'dono']);
        $animal = $this->criarAnimalAtivo($fazendaSemTitularA->id);

        $this->expectException(DomainException::class);
        $this->service->transferir($this->usuarioId, $fazendaSemTitularA->id, $fazendaSemTitularB->id, [$animal->id], 'chave-1');
    }

    /** INV-050 — Titulares diferentes, exige evento comercial formal. */
    public function test_fazendas_de_titulares_diferentes_recusa_transferencia(): void
    {
        $outroTitular = Titular::create(['documento' => '11144477735', 'tipo_documento' => 'cpf']);
        $fazendaOutroTitular = Fazenda::create(['nome' => 'Outro Titular', 'titular_id' => $outroTitular->id]);
        Papel::create(['usuario_id' => $this->usuarioId, 'fazenda_id' => $fazendaOutroTitular->id, 'papel' => 'dono']);
        $animal = $this->criarAnimalAtivo($this->fazendaOrigemId);

        $this->expectException(DomainException::class);
        $this->service->transferir($this->usuarioId, $this->fazendaOrigemId, $fazendaOutroTitular->id, [$animal->id], 'chave-1');
    }

    /** INV-051 — Animal de Lote fica fora do corte mínimo. */
    public function test_animal_vinculado_a_lote_e_recusado(): void
    {
        $lote = Lote::create(['fazenda_id' => $this->fazendaOrigemId, 'qtd_animais' => 5, 'custo_aquisicao' => 5000]);
        $animalDeLote = Animal::create(['fazenda_id' => $this->fazendaOrigemId, 'lote_id' => $lote->id, 'status' => 'ativo']);

        $this->expectException(DomainException::class);
        $this->service->transferir($this->usuarioId, $this->fazendaOrigemId, $this->fazendaDestinoId, [$animalDeLote->id], 'chave-1');
    }

    public function test_animal_ja_vendido_e_recusado(): void
    {
        $animal = $this->criarAnimalAtivo($this->fazendaOrigemId);
        $animal->update(['status' => 'vendido', 'data_saida' => now()->toDateString()]);

        $this->expectException(DomainException::class);
        $this->service->transferir($this->usuarioId, $this->fazendaOrigemId, $this->fazendaDestinoId, [$animal->id], 'chave-1');
    }

    public function test_um_animal_invalido_recusa_a_transferencia_inteira(): void
    {
        $animalValido = $this->criarAnimalAtivo($this->fazendaOrigemId);
        $animalInvalido = $this->criarAnimalAtivo($this->fazendaOrigemId);
        $animalInvalido->update(['status' => 'morto', 'data_saida' => now()->toDateString()]);

        try {
            $this->service->transferir($this->usuarioId, $this->fazendaOrigemId, $this->fazendaDestinoId, [$animalValido->id, $animalInvalido->id], 'chave-1');
            $this->fail('esperava DomainException');
        } catch (DomainException $e) {
            // nada foi escrito — tudo ou nada
            $this->assertSame('ativo', $animalValido->fresh()->status);
            $this->assertSame(0, TransferenciaFazenda::count());
        }
    }

    public function test_animal_de_outra_fazenda_e_recusado(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Terceira']);
        $animalDeOutraFazenda = $this->criarAnimalAtivo($outraFazenda->id);

        $this->expectException(DomainException::class);
        $this->service->transferir($this->usuarioId, $this->fazendaOrigemId, $this->fazendaDestinoId, [$animalDeOutraFazenda->id], 'chave-1');
    }

    public function test_fazenda_origem_igual_destino_e_recusada(): void
    {
        $animal = $this->criarAnimalAtivo($this->fazendaOrigemId);

        $this->expectException(DomainException::class);
        $this->service->transferir($this->usuarioId, $this->fazendaOrigemId, $this->fazendaOrigemId, [$animal->id], 'chave-1');
    }

    public function test_lista_de_animais_vazia_e_recusada(): void
    {
        $this->expectException(DomainException::class);
        $this->service->transferir($this->usuarioId, $this->fazendaOrigemId, $this->fazendaDestinoId, [], 'chave-1');
    }

    public function test_chave_idempotencia_vazia_e_recusada(): void
    {
        $animal = $this->criarAnimalAtivo($this->fazendaOrigemId);

        $this->expectException(DomainException::class);
        $this->service->transferir($this->usuarioId, $this->fazendaOrigemId, $this->fazendaDestinoId, [$animal->id], '   ');
    }

    /** Decisão do produtor: exige relação com as DUAS Fazendas. */
    public function test_usuario_sem_relacao_com_fazenda_destino_e_recusado(): void
    {
        $outroUsuarioId = Usuario::create(['nome' => 'Maria']);
        Papel::create(['usuario_id' => $outroUsuarioId->id, 'fazenda_id' => $this->fazendaOrigemId, 'papel' => 'dono']);
        $animal = $this->criarAnimalAtivo($this->fazendaOrigemId);

        $this->expectException(DomainException::class);
        $this->service->transferir($outroUsuarioId->id, $this->fazendaOrigemId, $this->fazendaDestinoId, [$animal->id], 'chave-1');
    }

    public function test_usuario_sem_relacao_com_fazenda_origem_e_recusado(): void
    {
        $outroUsuarioId = Usuario::create(['nome' => 'Maria']);
        Papel::create(['usuario_id' => $outroUsuarioId->id, 'fazenda_id' => $this->fazendaDestinoId, 'papel' => 'dono']);
        $animal = $this->criarAnimalAtivo($this->fazendaOrigemId);

        $this->expectException(DomainException::class);
        $this->service->transferir($outroUsuarioId->id, $this->fazendaOrigemId, $this->fazendaDestinoId, [$animal->id], 'chave-1');
    }

    public function test_transferir_multiplos_animais_de_uma_vez(): void
    {
        $a1 = $this->criarAnimalAtivo($this->fazendaOrigemId, 1000);
        $a2 = $this->criarAnimalAtivo($this->fazendaOrigemId, 2000);

        $resultado = $this->service->transferir($this->usuarioId, $this->fazendaOrigemId, $this->fazendaDestinoId, [$a1->id, $a2->id], 'chave-1');

        $this->assertCount(2, $resultado['animais_destino']);
        $this->assertSame('transferido', $a1->fresh()->status);
        $this->assertSame('transferido', $a2->fresh()->status);
    }
}
