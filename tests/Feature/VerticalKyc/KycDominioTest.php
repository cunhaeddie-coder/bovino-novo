<?php

namespace Tests\Feature\VerticalKyc;

use App\Models\Animal;
use App\Models\EmbargoIbama;
use App\Models\Fazenda;
use App\Models\Kyc;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\AnuncioService;
use App\Services\KycService;
use App\Services\NegociacaoService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 23 (KYC) — nasce de VERTICAL-KYC.md e
 * SCHEMA-CONTRATO-KYC.md. Reproduz LAB-FA-028 (aprovação automática no
 * caminho feliz) e o caminho de rejeição já coberto por VendorKycTest no
 * Atual (resultado reproduzido, nunca o mecanismo).
 */
class KycDominioTest extends TestCase
{
    use RefreshDatabase;

    private KycService $kyc;

    private AnuncioService $anuncios;

    private NegociacaoService $negociacoes;

    private int $fazendaId;

    private int $usuarioId;

    private const CPF_VALIDO = '11144477735';

    private const CNPJ_VALIDO = '11222333000181';

    protected function setUp(): void
    {
        parent::setUp();
        $this->kyc = app(KycService::class);
        $this->anuncios = app(AnuncioService::class);
        $this->negociacoes = app(NegociacaoService::class);

        $this->fazendaId = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->usuarioId = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->usuarioId, 'fazenda_id' => $this->fazendaId, 'papel' => 'dono']);
    }

    // ── KycService — caminho feliz e rejeição ───────────────────────────

    public function test_submeter_cpf_valido_sem_embargo_aprova(): void
    {
        $kyc = $this->kyc->submeter($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf');

        $this->assertSame('aprovado', $kyc->status);
        $this->assertNull($kyc->motivo_reprovacao);
    }

    public function test_submeter_cnpj_valido_sem_embargo_aprova(): void
    {
        $kyc = $this->kyc->submeter($this->usuarioId, $this->fazendaId, self::CNPJ_VALIDO, 'cnpj');

        $this->assertSame('aprovado', $kyc->status);
    }

    public function test_submeter_cpf_com_checksum_invalido_reprova(): void
    {
        $kyc = $this->kyc->submeter($this->usuarioId, $this->fazendaId, '11144477736', 'cpf'); // último dígito errado

        $this->assertSame('reprovado', $kyc->status);
        $this->assertSame('documento_invalido', $kyc->motivo_reprovacao);
    }

    public function test_submeter_cpf_com_todos_digitos_iguais_reprova(): void
    {
        $kyc = $this->kyc->submeter($this->usuarioId, $this->fazendaId, '11111111111', 'cpf');

        $this->assertSame('reprovado', $kyc->status);
        $this->assertSame('documento_invalido', $kyc->motivo_reprovacao);
    }

    /** INV-048 — embargo ativo reprova mesmo com checksum válido. */
    public function test_submeter_com_embargo_ibama_ativo_reprova_inv048(): void
    {
        EmbargoIbama::create(['documento' => self::CPF_VALIDO, 'situacao' => 'ativo']);

        $kyc = $this->kyc->submeter($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf');

        $this->assertSame('reprovado', $kyc->status);
        $this->assertSame('embargo_ibama', $kyc->motivo_reprovacao);
    }

    public function test_submeter_com_embargo_cancelado_nao_bloqueia(): void
    {
        EmbargoIbama::create(['documento' => self::CPF_VALIDO, 'situacao' => 'cancelado']);

        $kyc = $this->kyc->submeter($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf');

        $this->assertSame('aprovado', $kyc->status);
    }

    public function test_resubmissao_atualiza_o_mesmo_registro(): void
    {
        $primeiro = $this->kyc->submeter($this->usuarioId, $this->fazendaId, '11144477736', 'cpf'); // inválido
        $segundo = $this->kyc->submeter($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf'); // corrigido

        $this->assertSame($primeiro->id, $segundo->id);
        $this->assertSame('reprovado', $primeiro->status);
        $this->assertSame('aprovado', $segundo->fresh()->status);
        $this->assertSame(1, Kyc::where('fazenda_id', $this->fazendaId)->count());
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_pode_submeter(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Outra']);

        $this->expectException(DomainException::class);
        $this->kyc->submeter($this->usuarioId, $outraFazenda->id, self::CPF_VALIDO, 'cpf');
    }

    public function test_documento_vazio_e_recusado(): void
    {
        $this->expectException(DomainException::class);
        $this->kyc->submeter($this->usuarioId, $this->fazendaId, '   ', 'cpf');
    }

    public function test_tipo_documento_invalido_e_recusado(): void
    {
        $this->expectException(DomainException::class);
        $this->kyc->submeter($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'passaporte');
    }

    // ── AnuncioService — gate de KYC ao publicar (INV-046) ──────────────

    public function test_publicar_anuncio_exige_kyc_aprovado_inv046(): void
    {
        $animal = Animal::create(['fazenda_id' => $this->fazendaId, 'custo_aquisicao' => 1000, 'status' => 'ativo']);

        $this->expectException(DomainException::class);
        $this->anuncios->publicar($this->usuarioId, $this->fazendaId, 1000, [$animal->id]);
    }

    public function test_publicar_anuncio_funciona_com_kyc_aprovado(): void
    {
        $this->kyc->submeter($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf');
        $animal = Animal::create(['fazenda_id' => $this->fazendaId, 'custo_aquisicao' => 1000, 'status' => 'ativo']);

        $anuncio = $this->anuncios->publicar($this->usuarioId, $this->fazendaId, 1000, [$animal->id]);

        $this->assertSame('ativo', $anuncio->status);
        $this->assertNotNull($anuncio->publicado_em);
        $this->assertSame(1, $anuncio->animais()->count());
    }

    public function test_publicar_anuncio_com_kyc_reprovado_e_recusado(): void
    {
        $this->kyc->submeter($this->usuarioId, $this->fazendaId, '11144477736', 'cpf'); // reprovado
        $animal = Animal::create(['fazenda_id' => $this->fazendaId, 'custo_aquisicao' => 1000, 'status' => 'ativo']);

        $this->expectException(DomainException::class);
        $this->anuncios->publicar($this->usuarioId, $this->fazendaId, 1000, [$animal->id]);
    }

    public function test_publicar_anuncio_sem_relacao_com_fazenda_e_recusado(): void
    {
        $this->kyc->submeter($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf');
        $outraFazenda = Fazenda::create(['nome' => 'Outra']);
        $animal = Animal::create(['fazenda_id' => $this->fazendaId, 'custo_aquisicao' => 1000, 'status' => 'ativo']);

        $this->expectException(DomainException::class);
        $this->anuncios->publicar($this->usuarioId, $outraFazenda->id, 1000, [$animal->id]);
    }

    // ── NegociacaoService::propor() — gate de KYC do comprador (INV-047) ─

    public function test_propor_negociacao_exige_kyc_aprovado_do_comprador_inv047(): void
    {
        // Vendedor com KYC, publica de verdade.
        $this->kyc->submeter($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf');
        $animal = Animal::create(['fazenda_id' => $this->fazendaId, 'custo_aquisicao' => 1000, 'status' => 'ativo']);
        $anuncio = $this->anuncios->publicar($this->usuarioId, $this->fazendaId, 1000, [$animal->id]);

        // Comprador SEM KYC.
        $fazendaCompradora = Fazenda::create(['nome' => 'Compradora']);
        $usuarioComprador = Usuario::create(['nome' => 'Maria']);
        Papel::create(['usuario_id' => $usuarioComprador->id, 'fazenda_id' => $fazendaCompradora->id, 'papel' => 'dono']);

        $this->expectException(DomainException::class);
        $this->negociacoes->propor($usuarioComprador->id, $anuncio->id, $fazendaCompradora->id, 1000, 'neg-1');
    }

    public function test_propor_negociacao_funciona_com_kyc_aprovado_do_comprador(): void
    {
        $this->kyc->submeter($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf');
        $animal = Animal::create(['fazenda_id' => $this->fazendaId, 'custo_aquisicao' => 1000, 'status' => 'ativo']);
        $anuncio = $this->anuncios->publicar($this->usuarioId, $this->fazendaId, 1000, [$animal->id]);

        $fazendaCompradora = Fazenda::create(['nome' => 'Compradora']);
        $usuarioComprador = Usuario::create(['nome' => 'Maria']);
        Papel::create(['usuario_id' => $usuarioComprador->id, 'fazenda_id' => $fazendaCompradora->id, 'papel' => 'dono']);
        $this->kyc->submeter($usuarioComprador->id, $fazendaCompradora->id, self::CNPJ_VALIDO, 'cnpj');

        $resultado = $this->negociacoes->propor($usuarioComprador->id, $anuncio->id, $fazendaCompradora->id, 1000, 'neg-2');

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertSame('proposta', $resultado['negociacao']->status);
    }
}
