<?php

namespace Tests\Feature\VerticalProtocoloReprodutivo;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ProtocoloReprodutivoService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProtocoloReprodutivoFronteirasTest extends TestCase
{
    use RefreshDatabase;

    private ProtocoloReprodutivoService $protocolos;

    private int $fazenda;

    private int $jose;

    protected function setUp(): void
    {
        parent::setUp();
        $this->protocolos = app(ProtocoloReprodutivoService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
    }

    private function criarAnimal(): int
    {
        return Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id;
    }

    public function test_chave_idempotencia_vazia_e_recusada(): void
    {
        $animal = $this->criarAnimal();

        $this->assertThrows(
            fn () => $this->protocolos->iniciar($this->jose, $this->fazenda, [$animal], '2026-01-01 08:00:00', ''),
            DomainException::class
        );
    }

    public function test_protocolo_sem_nenhum_animal_e_recusado(): void
    {
        $this->assertThrows(
            fn () => $this->protocolos->iniciar($this->jose, $this->fazenda, [], '2026-01-01 08:00:00', 'x'),
            DomainException::class
        );
    }

    public function test_animal_de_outra_fazenda_e_recusado(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'B'])->id;
        $animalDeOutraFazenda = Animal::create(['fazenda_id' => $outraFazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id;

        $this->assertThrows(
            fn () => $this->protocolos->iniciar($this->jose, $this->fazenda, [$animalDeOutraFazenda], '2026-01-01 08:00:00', 'y'),
            DomainException::class
        );
    }

    public function test_tipo_etapa_invalido_e_recusado(): void
    {
        $animal = $this->criarAnimal();
        $registro = $this->protocolos->iniciar($this->jose, $this->fazenda, [$animal], '2026-01-01 08:00:00', 'z');

        $this->assertThrows(
            fn () => $this->protocolos->cumprirEtapa($this->jose, $registro['protocolo']->id, 'vacina', '2026-01-08 08:00:00'),
            DomainException::class
        );
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_inicia_protocolo(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        $animal = $this->criarAnimal();

        $this->assertThrows(
            fn () => $this->protocolos->iniciar($mariazinha, $this->fazenda, [$animal], '2026-01-01 08:00:00', 'ataque-mariazinha'),
            DomainException::class
        );
    }

    public function test_cumprir_etapa_de_protocolo_ja_concluido_e_recusado(): void
    {
        $animal = $this->criarAnimal();
        $registro = $this->protocolos->iniciar($this->jose, $this->fazenda, [$animal], '2026-01-01 08:00:00', 'w');
        $this->protocolos->cumprirEtapa($this->jose, $registro['protocolo']->id, 'prostaglandina', '2026-01-08 08:00:00');
        $this->protocolos->cumprirEtapa($this->jose, $registro['protocolo']->id, 'retirada_ia', '2026-01-10 08:00:00');

        // As 3 já foram cumpridas — chamar de novo pra qualquer tipo agora é reenvio, nunca erro.
        $reenvio = $this->protocolos->cumprirEtapa($this->jose, $registro['protocolo']->id, 'implante', '2026-01-01 09:00:00');
        $this->assertTrue($reenvio['reenvio_detectado']);
    }
}
