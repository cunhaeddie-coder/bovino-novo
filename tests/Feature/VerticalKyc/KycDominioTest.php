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
use App\Services\TitularService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 23 (KYC), reaberto pelo Vertical 25
 * (Titular) — nasce de VERTICAL-KYC.md/SCHEMA-CONTRATO-KYC.md e
 * VERTICAL-TITULAR.md/SCHEMA-CONTRATO-TITULAR.md. Reproduz LAB-FA-028
 * (aprovação automática no caminho feliz) e o caminho de rejeição já
 * coberto por VendorKycTest no Atual (resultado reproduzido, nunca o
 * mecanismo).
 */
class KycDominioTest extends TestCase
{
    use RefreshDatabase;

    private TitularService $titulares;

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
        $this->titulares = app(TitularService::class);
        $this->kyc = app(KycService::class);
        $this->anuncios = app(AnuncioService::class);
        $this->negociacoes = app(NegociacaoService::class);

        $this->fazendaId = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->usuarioId = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->usuarioId, 'fazenda_id' => $this->fazendaId, 'papel' => 'dono']);
    }

    // ── KycService — caminho feliz e rejeição ───────────────────────────

    public function test_submeter_sem_titular_vinculado_e_recusado(): void
    {
        $this->expectException(DomainException::class);
        $this->kyc->submeter($this->usuarioId, $this->fazendaId);
    }

    public function test_submeter_cpf_valido_sem_embargo_aprova(): void
    {
        $this->titulares->vincular($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf');

        $kyc = $this->kyc->submeter($this->usuarioId, $this->fazendaId);

        $this->assertSame('aprovado', $kyc->status);
        $this->assertNull($kyc->motivo_reprovacao);
    }

    public function test_submeter_cnpj_valido_sem_embargo_aprova(): void
    {
        $this->titulares->vincular($this->usuarioId, $this->fazendaId, self::CNPJ_VALIDO, 'cnpj');

        $kyc = $this->kyc->submeter($this->usuarioId, $this->fazendaId);

        $this->assertSame('aprovado', $kyc->status);
    }

    public function test_submeter_cpf_com_checksum_invalido_reprova(): void
    {
        $this->titulares->vincular($this->usuarioId, $this->fazendaId, '11144477736', 'cpf'); // último dígito errado

        $kyc = $this->kyc->submeter($this->usuarioId, $this->fazendaId);

        $this->assertSame('reprovado', $kyc->status);
        $this->assertSame('documento_invalido', $kyc->motivo_reprovacao);
    }

    public function test_submeter_cpf_com_todos_digitos_iguais_reprova(): void
    {
        $this->titulares->vincular($this->usuarioId, $this->fazendaId, '11111111111', 'cpf');

        $kyc = $this->kyc->submeter($this->usuarioId, $this->fazendaId);

        $this->assertSame('reprovado', $kyc->status);
        $this->assertSame('documento_invalido', $kyc->motivo_reprovacao);
    }

    /** INV-048 — embargo ativo reprova mesmo com checksum válido. */
    public function test_submeter_com_embargo_ibama_ativo_reprova_inv048(): void
    {
        EmbargoIbama::create(['documento' => self::CPF_VALIDO, 'situacao' => 'ativo']);
        $this->titulares->vincular($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf');

        $kyc = $this->kyc->submeter($this->usuarioId, $this->fazendaId);

        $this->assertSame('reprovado', $kyc->status);
        $this->assertSame('embargo_ibama', $kyc->motivo_reprovacao);
    }

    public function test_submeter_com_embargo_cancelado_nao_bloqueia(): void
    {
        EmbargoIbama::create(['documento' => self::CPF_VALIDO, 'situacao' => 'cancelado']);
        $this->titulares->vincular($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf');

        $kyc = $this->kyc->submeter($this->usuarioId, $this->fazendaId);

        $this->assertSame('aprovado', $kyc->status);
    }

    /** Resubmissão de verdade: MESMO Titular, resultado muda porque um embargo foi descoberto entre as duas chamadas. */
    public function test_resubmissao_do_mesmo_titular_atualiza_o_mesmo_registro(): void
    {
        $this->titulares->vincular($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf');
        $primeiro = $this->kyc->submeter($this->usuarioId, $this->fazendaId);

        EmbargoIbama::create(['documento' => self::CPF_VALIDO, 'situacao' => 'ativo']);
        $segundo = $this->kyc->submeter($this->usuarioId, $this->fazendaId);

        $this->assertSame($primeiro->id, $segundo->id);
        $this->assertSame('aprovado', $primeiro->status);
        $this->assertSame('reprovado', $segundo->fresh()->status);
        $this->assertSame(1, Kyc::count());
    }

    /** Decisão do produtor: trocar de Titular (documento diferente) sempre exige novo KYC — nunca reaproveita o Kyc do Titular anterior. */
    public function test_trocar_de_titular_cria_kyc_novo_nunca_reaproveita_o_anterior(): void
    {
        $this->titulares->vincular($this->usuarioId, $this->fazendaId, '11144477736', 'cpf'); // inválido
        $primeiro = $this->kyc->submeter($this->usuarioId, $this->fazendaId);

        $this->titulares->vincular($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf'); // Titular DIFERENTE (documento mudou)
        $segundo = $this->kyc->submeter($this->usuarioId, $this->fazendaId);

        $this->assertNotSame($primeiro->id, $segundo->id);
        $this->assertSame('reprovado', $primeiro->fresh()->status, 'kyc_do_titular_antigo_permanece_intocado');
        $this->assertSame('aprovado', $segundo->status);
        $this->assertSame(2, Kyc::count());
    }

    /** Titular do Vertical 25 — duas Fazendas do mesmo documento compartilham o Kyc. */
    public function test_duas_fazendas_do_mesmo_titular_compartilham_o_kyc(): void
    {
        $this->titulares->vincular($this->usuarioId, $this->fazendaId, self::CNPJ_VALIDO, 'cnpj');
        $this->kyc->submeter($this->usuarioId, $this->fazendaId);

        $outraFazenda = Fazenda::create(['nome' => 'Fazenda Irmã']);
        Papel::create(['usuario_id' => $this->usuarioId, 'fazenda_id' => $outraFazenda->id, 'papel' => 'dono']);
        $this->titulares->vincular($this->usuarioId, $outraFazenda->id, self::CNPJ_VALIDO, 'cnpj'); // mesmo CNPJ

        // A segunda Fazenda já publica sem precisar de novo submeter() — o
        // KYC é do Titular, que já está aprovado.
        $animal = Animal::create(['fazenda_id' => $outraFazenda->id, 'custo_aquisicao' => 1000, 'status' => 'ativo']);
        $anuncio = $this->anuncios->publicar($this->usuarioId, $outraFazenda->id, 1000, [$animal->id]);

        $this->assertSame('ativo', $anuncio->status);
        $this->assertSame(1, Kyc::count());
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_pode_submeter(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Outra']);

        $this->expectException(DomainException::class);
        $this->kyc->submeter($this->usuarioId, $outraFazenda->id);
    }

    // ── AnuncioService — gate de KYC ao publicar (INV-046) ──────────────

    public function test_publicar_anuncio_sem_titular_vinculado_e_recusado(): void
    {
        $animal = Animal::create(['fazenda_id' => $this->fazendaId, 'custo_aquisicao' => 1000, 'status' => 'ativo']);

        $this->expectException(DomainException::class);
        $this->anuncios->publicar($this->usuarioId, $this->fazendaId, 1000, [$animal->id]);
    }

    public function test_publicar_anuncio_exige_kyc_aprovado_inv046(): void
    {
        $this->titulares->vincular($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf'); // vinculado, mas sem KYC submetido ainda
        $animal = Animal::create(['fazenda_id' => $this->fazendaId, 'custo_aquisicao' => 1000, 'status' => 'ativo']);

        $this->expectException(DomainException::class);
        $this->anuncios->publicar($this->usuarioId, $this->fazendaId, 1000, [$animal->id]);
    }

    public function test_publicar_anuncio_funciona_com_kyc_aprovado(): void
    {
        $this->titulares->vincular($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf');
        $this->kyc->submeter($this->usuarioId, $this->fazendaId);
        $animal = Animal::create(['fazenda_id' => $this->fazendaId, 'custo_aquisicao' => 1000, 'status' => 'ativo']);

        $anuncio = $this->anuncios->publicar($this->usuarioId, $this->fazendaId, 1000, [$animal->id]);

        $this->assertSame('ativo', $anuncio->status);
        $this->assertNotNull($anuncio->publicado_em);
        $this->assertSame(1, $anuncio->animais()->count());
    }

    public function test_publicar_anuncio_com_kyc_reprovado_e_recusado(): void
    {
        $this->titulares->vincular($this->usuarioId, $this->fazendaId, '11144477736', 'cpf'); // reprovado
        $this->kyc->submeter($this->usuarioId, $this->fazendaId);
        $animal = Animal::create(['fazenda_id' => $this->fazendaId, 'custo_aquisicao' => 1000, 'status' => 'ativo']);

        $this->expectException(DomainException::class);
        $this->anuncios->publicar($this->usuarioId, $this->fazendaId, 1000, [$animal->id]);
    }

    public function test_publicar_anuncio_sem_relacao_com_fazenda_e_recusado(): void
    {
        $this->titulares->vincular($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf');
        $this->kyc->submeter($this->usuarioId, $this->fazendaId);
        $outraFazenda = Fazenda::create(['nome' => 'Outra']);
        $animal = Animal::create(['fazenda_id' => $this->fazendaId, 'custo_aquisicao' => 1000, 'status' => 'ativo']);

        $this->expectException(DomainException::class);
        $this->anuncios->publicar($this->usuarioId, $outraFazenda->id, 1000, [$animal->id]);
    }

    // ── NegociacaoService::propor() — gate de KYC do comprador (INV-047) ─

    public function test_propor_negociacao_exige_kyc_aprovado_do_comprador_inv047(): void
    {
        $this->titulares->vincular($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf');
        $this->kyc->submeter($this->usuarioId, $this->fazendaId);
        $animal = Animal::create(['fazenda_id' => $this->fazendaId, 'custo_aquisicao' => 1000, 'status' => 'ativo']);
        $anuncio = $this->anuncios->publicar($this->usuarioId, $this->fazendaId, 1000, [$animal->id]);

        // Comprador SEM Titular/KYC.
        $fazendaCompradora = Fazenda::create(['nome' => 'Compradora']);
        $usuarioComprador = Usuario::create(['nome' => 'Maria']);
        Papel::create(['usuario_id' => $usuarioComprador->id, 'fazenda_id' => $fazendaCompradora->id, 'papel' => 'dono']);

        $this->expectException(DomainException::class);
        $this->negociacoes->propor($usuarioComprador->id, $anuncio->id, $fazendaCompradora->id, 1000, 'neg-1');
    }

    public function test_propor_negociacao_funciona_com_kyc_aprovado_do_comprador(): void
    {
        $this->titulares->vincular($this->usuarioId, $this->fazendaId, self::CPF_VALIDO, 'cpf');
        $this->kyc->submeter($this->usuarioId, $this->fazendaId);
        $animal = Animal::create(['fazenda_id' => $this->fazendaId, 'custo_aquisicao' => 1000, 'status' => 'ativo']);
        $anuncio = $this->anuncios->publicar($this->usuarioId, $this->fazendaId, 1000, [$animal->id]);

        $fazendaCompradora = Fazenda::create(['nome' => 'Compradora']);
        $usuarioComprador = Usuario::create(['nome' => 'Maria']);
        Papel::create(['usuario_id' => $usuarioComprador->id, 'fazenda_id' => $fazendaCompradora->id, 'papel' => 'dono']);
        $this->titulares->vincular($usuarioComprador->id, $fazendaCompradora->id, self::CNPJ_VALIDO, 'cnpj');
        $this->kyc->submeter($usuarioComprador->id, $fazendaCompradora->id);

        $resultado = $this->negociacoes->propor($usuarioComprador->id, $anuncio->id, $fazendaCompradora->id, 1000, 'neg-2');

        $this->assertFalse($resultado['reenvio_detectado']);
        $this->assertSame('proposta', $resultado['negociacao']->status);
    }
}
