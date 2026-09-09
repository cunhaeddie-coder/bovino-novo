<?php

namespace Tests\Feature\VerticalReclassificacao;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\ReclassificacaoCategoriaService;
use App\Services\ReclassificacaoFinalidadeService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReclassificacaoFronteirasTest extends TestCase
{
    use RefreshDatabase;

    private ReclassificacaoCategoriaService $categoria;

    private ReclassificacaoFinalidadeService $finalidade;

    private int $fazenda;

    private int $jose;

    private int $animal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->categoria = app(ReclassificacaoCategoriaService::class);
        $this->finalidade = app(ReclassificacaoFinalidadeService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->animal = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 3000, 'status' => 'ativo'])->id;
    }

    public function test_chave_idempotencia_vazia_e_recusada_categoria(): void
    {
        $this->assertThrows(
            fn () => $this->categoria->registrar($this->jose, $this->fazenda, [$this->animal], 'novilho', ''),
            DomainException::class
        );
    }

    public function test_chave_idempotencia_vazia_e_recusada_finalidade(): void
    {
        $this->assertThrows(
            fn () => $this->finalidade->registrar($this->jose, $this->fazenda, [$this->animal], 'recria', ''),
            DomainException::class
        );
    }

    public function test_lista_vazia_de_animais_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->categoria->registrar($this->jose, $this->fazenda, [], 'novilho', 'x'),
            DomainException::class
        );
    }

    public function test_categoria_nova_vazia_e_recusada(): void
    {
        $this->assertThrows(
            fn () => $this->categoria->registrar($this->jose, $this->fazenda, [$this->animal], '', 'x'),
            DomainException::class
        );
    }

    public function test_animal_de_outra_fazenda_e_recusado(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'B'])->id;
        $animalDeOutraFazenda = Animal::create(['fazenda_id' => $outraFazenda, 'lote_id' => null, 'custo_aquisicao' => 3000, 'status' => 'ativo'])->id;

        $this->assertThrows(
            fn () => $this->categoria->registrar($this->jose, $this->fazenda, [$this->animal, $animalDeOutraFazenda], 'novilho', 'y'),
            DomainException::class
        );
    }

    public function test_animal_ja_morto_e_recusado(): void
    {
        $morto = Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 3000, 'status' => 'morto'])->id;

        $this->assertThrows(
            fn () => $this->categoria->registrar($this->jose, $this->fazenda, [$morto], 'novilho', 'z'),
            DomainException::class
        );
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_reclassifica(): void
    {
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;

        $this->assertThrows(
            fn () => $this->categoria->registrar($mariazinha, $this->fazenda, [$this->animal], 'novilho', 'ataque-mariazinha'),
            DomainException::class
        );
    }
}
