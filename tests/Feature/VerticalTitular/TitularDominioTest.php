<?php

namespace Tests\Feature\VerticalTitular;

use App\Models\Fazenda;
use App\Models\Kyc;
use App\Models\Papel;
use App\Models\Titular;
use App\Models\Usuario;
use App\Services\KycService;
use App\Services\TitularService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 25 (Titular) — nasce de VERTICAL-TITULAR.md
 * e SCHEMA-CONTRATO-TITULAR.md. Correção de processo: titularidade jurídica
 * vira entidade própria, compartilhável por várias Fazendas do mesmo
 * produtor.
 */
class TitularDominioTest extends TestCase
{
    use RefreshDatabase;

    private TitularService $service;

    private int $fazendaId;

    private int $usuarioId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TitularService::class);
        $this->fazendaId = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->usuarioId = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->usuarioId, 'fazenda_id' => $this->fazendaId, 'papel' => 'dono']);
    }

    public function test_vincular_cria_titular_novo(): void
    {
        $fazenda = $this->service->vincular($this->usuarioId, $this->fazendaId, '11144477735', 'cpf');

        $this->assertNotNull($fazenda->titular_id);
        $this->assertSame('11144477735', $fazenda->titular->documento);
        $this->assertSame('cpf', $fazenda->titular->tipo_documento);
    }

    public function test_vincular_reaproveita_titular_existente_pra_outra_fazenda(): void
    {
        $this->service->vincular($this->usuarioId, $this->fazendaId, '11222333000181', 'cnpj');

        $outraFazenda = Fazenda::create(['nome' => 'Fazenda Irmã']);
        Papel::create(['usuario_id' => $this->usuarioId, 'fazenda_id' => $outraFazenda->id, 'papel' => 'dono']);
        $fazenda2 = $this->service->vincular($this->usuarioId, $outraFazenda->id, '11222333000181', 'cnpj');

        $this->assertSame(1, Titular::count());
        $this->assertSame(2, $fazenda2->titular->fazendas()->count());
    }

    /** Decisão do produtor: pode trocar de Titular, substitui o anterior. */
    public function test_vincular_de_novo_substitui_o_titular_anterior(): void
    {
        $this->service->vincular($this->usuarioId, $this->fazendaId, '11144477735', 'cpf');
        $fazenda = $this->service->vincular($this->usuarioId, $this->fazendaId, '11222333000181', 'cnpj');

        $this->assertSame('11222333000181', $fazenda->titular->documento);
        $this->assertSame(2, Titular::count()); // o antigo continua existindo, só não vinculado mais
    }

    /** Decisão do produtor: trocar de Titular sempre exige novo KYC. */
    public function test_trocar_de_titular_deixa_fazenda_sem_kyc_aprovado(): void
    {
        $this->service->vincular($this->usuarioId, $this->fazendaId, '11144477735', 'cpf');
        app(KycService::class)->submeter($this->usuarioId, $this->fazendaId);

        $fazenda = $this->service->vincular($this->usuarioId, $this->fazendaId, '11222333000181', 'cnpj');

        $kycDoNovoTitular = Kyc::where('titular_id', $fazenda->titular_id)->first();
        $this->assertNull($kycDoNovoTitular, 'novo_titular_nunca_teve_kyc_submetido');
    }

    public function test_usuario_sem_relacao_com_fazenda_nao_pode_vincular(): void
    {
        $outraFazenda = Fazenda::create(['nome' => 'Outra']);

        $this->expectException(DomainException::class);
        $this->service->vincular($this->usuarioId, $outraFazenda->id, '11144477735', 'cpf');
    }

    public function test_documento_vazio_e_recusado(): void
    {
        $this->expectException(DomainException::class);
        $this->service->vincular($this->usuarioId, $this->fazendaId, '   ', 'cpf');
    }

    public function test_tipo_documento_invalido_e_recusado(): void
    {
        $this->expectException(DomainException::class);
        $this->service->vincular($this->usuarioId, $this->fazendaId, '11144477735', 'passaporte');
    }
}
