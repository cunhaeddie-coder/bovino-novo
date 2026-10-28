<?php

namespace Tests\Feature\Invariantes;

use App\Models\Animal;
use App\Models\Anuncio;
use App\Models\Fazenda;
use App\Models\FormaPagamento;
use App\Models\Kyc;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Titular;
use App\Models\Usuario;
use App\Services\AnuncioService;
use App\Services\AuthService;
use App\Services\FolhaPagamentoService;
use App\Services\FormaPagamentoService;
use App\Services\FuncionarioService;
use App\Services\VendaService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Furos achados ao conferir o documento de invariantes contra o código
 * (roteiro até a publicação, etapa 1, item 5 — 27/10/2026). Cada teste
 * descreve o que a invariante promete e falha hoje; nenhum foi corrigido sem
 * a conversa com o produtor.
 */
class FurosEncontradosTest extends TestCase
{
    use RefreshDatabase;

    /** INV-002 — venda sem valor não tem consequência financeira real. */
    public function test_venda_com_valor_zero_e_recusada_inv002(): void
    {
        [$fazenda, $usuario] = $this->fazendaComDono('Fazenda Alegria', 'José');
        $animal = $this->animal($fazenda);

        $this->expectException(DomainException::class);
        app(VendaService::class)->registrar($usuario, $fazenda, [$animal], 0.00, '2026-01-01 10:00:00', 'venda-zero');
    }

    /** INV-008 — venda lançada depois do fato: a saída do animal e o vencimento à vista ficam na data real. */
    public function test_venda_retroativa_fica_na_data_real_inv008(): void
    {
        [$fazenda, $usuario] = $this->fazendaComDono('Fazenda Alegria', 'José');
        $animal = $this->animal($fazenda);

        $venda = app(VendaService::class)->registrar($usuario, $fazenda, [$animal], 4500.00, '2026-01-01 10:00:00', 'venda-janeiro')['venda'];

        $this->assertSame('2026-01-01', Animal::find($animal)->data_saida?->format('Y-m-d'), 'saida_do_animal_na_data_da_venda');
        $forma = FormaPagamento::where('obrigacao_financeira_id', ObrigacaoFinanceira::where('venda_id', $venda->id)->sole()->id)->sole();
        $this->assertSame('2026-01-01', $forma->vencimento->format('Y-m-d'), 'a_vista_vence_no_dia_da_venda');
    }

    /** INV-023 — vendido fora do Marketplace, o animal não pode seguir anunciado na vitrine. */
    public function test_anuncio_sai_da_vitrine_quando_o_animal_e_vendido_por_outro_canal_inv023(): void
    {
        [$fazenda, $usuario] = $this->fazendaComDono('Fazenda Alegria', 'José');
        $animal = $this->animal($fazenda);
        $anuncio = Anuncio::create(['fazenda_id' => $fazenda, 'preco_total' => 4500, 'status' => 'ativo', 'publicado_em' => '2026-01-01']);
        $anuncio->animais()->attach($animal);

        app(VendaService::class)->registrar($usuario, $fazenda, [$animal], 4500.00, '2026-01-02 10:00:00', 'venda-direta');

        $visitante = app(AuthService::class)->registrar('Maria', 'maria@sitio.com', null, 'senha123');
        $token = $this->postJson('/api/auth/login', ['identificador' => $visitante->email, 'senha' => 'senha123'])->json('token');
        $vitrine = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/anuncios')->assertOk();

        $this->assertNotContains($anuncio->id, collect($vitrine->json('anuncios'))->pluck('id')->all());
    }

    /** INV-029 — um anúncio nunca carrega animal de outra Fazenda. */
    public function test_anuncio_com_animal_de_outra_fazenda_e_recusado_inv029(): void
    {
        [$fazenda, $usuario] = $this->fazendaComDono('Fazenda Alegria', 'José');
        [$vizinha] = $this->fazendaComDono('Fazenda Vizinha', 'Pedro');
        $this->aprovarKyc($fazenda);
        $animalDaVizinha = $this->animal($vizinha);

        $this->expectException(DomainException::class);
        app(AnuncioService::class)->publicar($usuario, $fazenda, 4500.00, [$animalDaVizinha]);
    }

    /** INV-031 — editar uma parcela para menos nunca deixa a soma abaixo do total (e a conta "paga" sem estar). */
    public function test_editar_parcela_para_menos_nunca_deixa_a_soma_abaixo_do_total_inv031(): void
    {
        [$fazenda, $usuario] = $this->fazendaComDono('Fazenda Alegria', 'José');
        app(FuncionarioService::class)->contratar($usuario, $fazenda, 'Eddie', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', 'funcionario-1');
        $folha = app(FolhaPagamentoService::class)->gerarMes($usuario, $fazenda, '2026-01')['geradas'][0];
        $obrigacao = ObrigacaoFinanceira::where('folha_pagamento_id', $folha->id)->sole();
        $forma = FormaPagamento::where('obrigacao_financeira_id', $obrigacao->id)->sole();

        try {
            app(FormaPagamentoService::class)->editar($usuario, $forma->id, ['valor' => 1000.00]);
        } catch (DomainException) {
            // Recusar a edição também cumpre a invariante.
        }

        $this->assertEqualsWithDelta(3000.00, (float) FormaPagamento::where('obrigacao_financeira_id', $obrigacao->id)->sum('valor'), 0.01, 'soma_das_formas_igual_ao_total');
    }

    /** @return array{0: int, 1: int} [fazenda_id, usuario_id] */
    private function fazendaComDono(string $fazenda, string $dono): array
    {
        $fazendaId = Fazenda::create(['nome' => $fazenda])->id;
        $usuarioId = Usuario::create(['nome' => $dono])->id;
        Papel::create(['usuario_id' => $usuarioId, 'fazenda_id' => $fazendaId, 'papel' => 'dono']);

        return [$fazendaId, $usuarioId];
    }

    private function animal(int $fazenda): int
    {
        return Animal::create(['fazenda_id' => $fazenda, 'lote_id' => null, 'custo_aquisicao' => 1000, 'status' => 'ativo'])->id;
    }

    private function aprovarKyc(int $fazenda): void
    {
        $titular = Titular::create(['documento' => uniqid('doc'), 'tipo_documento' => 'cpf']);
        Fazenda::where('id', $fazenda)->update(['titular_id' => $titular->id]);
        Kyc::create(['titular_id' => $titular->id, 'status' => 'aprovado', 'verificado_em' => now()]);
    }
}
