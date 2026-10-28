<?php

namespace Tests\Feature\Invariantes;

use App\Models\Animal;
use App\Models\Anuncio;
use App\Models\Fazenda;
use App\Models\FormaPagamento;
use App\Models\Kyc;
use App\Models\Lancamento;
use App\Models\LancamentoFiscal;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Titular;
use App\Models\Usuario;
use App\Services\AnuncioService;
use App\Services\AuthService;
use App\Services\FolhaPagamentoService;
use App\Services\FormaPagamentoService;
use App\Services\FuncionarioService;
use App\Services\NegociacaoService;
use App\Services\OutboxService;
use App\Services\VendaService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Furos achados ao conferir o documento de invariantes contra o código
 * (roteiro até a publicação, etapa 1, item 5 — 27/10/2026). Cada teste
 * falhava antes da correção; as correções seguem a decisão do produtor
 * ("aprovo as correções, parcela A"). O furo da INV-020 (prévia da tela de
 * Vendas sem imposto) e a segunda parte da INV-008 (campo de data real em
 * liquidar, transferência, GTA e Separação) ficaram para as etapas 5 e 4.
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

    public function test_venda_com_valor_negativo_e_correcao_para_zero_sao_recusadas_inv002(): void
    {
        [$fazenda, $usuario] = $this->fazendaComDono('Fazenda Alegria', 'José');
        $animais = [$this->animal($fazenda), $this->animal($fazenda)];
        $vendas = app(VendaService::class);

        $this->assertRecusa(fn () => $vendas->registrar($usuario, $fazenda, $animais, -10.00, '2026-01-01 10:00:00', 'venda-negativa'));
        $this->assertSame(['ativo', 'ativo'], Animal::whereIn('id', $animais)->orderBy('id')->pluck('status')->all(), 'recusa_sem_efeito_parcial');

        $venda = $vendas->registrar($usuario, $fazenda, $animais, 9000.00, '2026-01-01 10:00:00', 'venda-ok')['venda'];
        $this->assertRecusa(fn () => $vendas->corrigir($usuario, $venda->id, [$animais[0]], 0.00, 'correcao-zero'));
    }

    /** INV-008 — venda lançada depois do fato: saída, pagamento à vista e lançamento fiscal na data real. */
    public function test_venda_retroativa_fica_na_data_real_inv008(): void
    {
        [$fazenda, $usuario] = $this->fazendaComDono('Fazenda Alegria', 'José');
        $animal = $this->animal($fazenda);

        $venda = app(VendaService::class)->registrar($usuario, $fazenda, [$animal], 4500.00, '2026-01-01 10:00:00', 'venda-janeiro')['venda'];

        $this->assertSame('2026-01-01', Animal::find($animal)->data_saida?->format('Y-m-d'), 'saida_do_animal_na_data_da_venda');
        $forma = FormaPagamento::where('obrigacao_financeira_id', ObrigacaoFinanceira::where('venda_id', $venda->id)->sole()->id)->sole();
        $this->assertSame('2026-01-01', $forma->vencimento->format('Y-m-d'), 'a_vista_vence_no_dia_da_venda');
        $this->assertSame('2026-01-01', $forma->pago_em->format('Y-m-d'), 'a_vista_paga_no_dia_da_venda');
        $this->assertSame('2026-01-01 10:00:00', LancamentoFiscal::where('venda_id', $venda->id)->sole()->data_lancamento->format('Y-m-d H:i:s'), 'lancamento_fiscal_na_data_da_venda');
    }

    /** INV-008 — o lançamento automático na Carteira fica no dia do pagamento, não no dia em que a varredura rodou. */
    public function test_lancamento_da_carteira_fica_na_data_do_pagamento_inv008(): void
    {
        [$fazenda, $usuario] = $this->fazendaComDono('Fazenda Alegria', 'José');
        $titular = Titular::create(['documento' => '11222333000181', 'tipo_documento' => 'cnpj']);
        Fazenda::where('id', $fazenda)->update(['titular_id' => $titular->id]);
        $animal = $this->animal($fazenda);

        app(VendaService::class)->registrar($usuario, $fazenda, [$animal], 4500.00, '2026-01-01 10:00:00', 'venda-janeiro');
        app(OutboxService::class)->processarPendentes();

        $this->assertSame('2026-01-01', Lancamento::where('origem', 'automatico')->sole()->data_lancamento->format('Y-m-d'));
    }

    /** INV-023 — vendido fora do Marketplace, o animal não pode seguir anunciado. */
    public function test_anuncio_sai_da_vitrine_quando_o_animal_e_vendido_por_outro_canal_inv023(): void
    {
        [$fazenda, $usuario] = $this->fazendaComDono('Fazenda Alegria', 'José');
        $animal = $this->animal($fazenda);
        $anuncio = Anuncio::create(['fazenda_id' => $fazenda, 'preco_total' => 4500, 'status' => 'ativo', 'publicado_em' => '2026-01-01']);
        $anuncio->animais()->attach($animal);

        app(VendaService::class)->registrar($usuario, $fazenda, [$animal], 4500.00, '2026-01-02 10:00:00', 'venda-direta');

        $gerente = app(AuthService::class)->registrar('Maria', 'maria@fazenda.com', null, 'senha123');
        Papel::create(['usuario_id' => $gerente->id, 'fazenda_id' => $fazenda, 'papel' => 'gerente']);
        $token = $this->postJson('/api/auth/login', ['identificador' => $gerente->email, 'senha' => 'senha123'])->json('token');

        $vitrine = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/anuncios')->assertOk();
        $this->assertNotContains($anuncio->id, collect($vitrine->json('anuncios'))->pluck('id')->all(), 'fora_da_vitrine');

        $meus = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/anuncios?fazenda_id={$fazenda}")->assertOk();
        $this->assertSame('indisponivel', collect($meus->json('anuncios'))->firstWhere('id', $anuncio->id)['situacao'], 'dono_ve_que_ficou_indisponivel');
    }

    public function test_proposta_para_anuncio_com_animal_ja_vendido_e_recusada_inv023(): void
    {
        [$fazenda, $usuario] = $this->fazendaComDono('Fazenda Alegria', 'José');
        [$compradora, $maria] = $this->fazendaComDono('Sítio Comprador', 'Maria');
        $this->aprovarKyc($compradora);
        $animal = $this->animal($fazenda);
        $anuncio = Anuncio::create(['fazenda_id' => $fazenda, 'preco_total' => 4500, 'status' => 'ativo', 'publicado_em' => '2026-01-01']);
        $anuncio->animais()->attach($animal);

        app(VendaService::class)->registrar($usuario, $fazenda, [$animal], 4500.00, '2026-01-02 10:00:00', 'venda-direta');

        $this->assertRecusa(fn () => app(NegociacaoService::class)->propor($maria, $anuncio->id, $compradora, 4500, 'proposta'));
    }

    /** INV-029 — um anúncio nunca carrega animal de outra Fazenda (nem animal que já saiu do rebanho). */
    public function test_anuncio_com_animal_de_outra_fazenda_e_recusado_inv029(): void
    {
        [$fazenda, $usuario] = $this->fazendaComDono('Fazenda Alegria', 'José');
        [$vizinha] = $this->fazendaComDono('Fazenda Vizinha', 'Pedro');
        $this->aprovarKyc($fazenda);
        $animalDaVizinha = $this->animal($vizinha);

        $this->expectException(DomainException::class);
        app(AnuncioService::class)->publicar($usuario, $fazenda, 4500.00, [$animalDaVizinha]);
    }

    public function test_anuncio_com_animal_ja_vendido_e_recusado_e_o_valido_passa_inv029(): void
    {
        [$fazenda, $usuario] = $this->fazendaComDono('Fazenda Alegria', 'José');
        $this->aprovarKyc($fazenda);
        $vendido = $this->animal($fazenda);
        $ativo = $this->animal($fazenda);
        app(VendaService::class)->registrar($usuario, $fazenda, [$vendido], 4500.00, '2026-01-02 10:00:00', 'venda-direta');
        $anuncios = app(AnuncioService::class);

        $this->assertRecusa(fn () => $anuncios->publicar($usuario, $fazenda, 4500.00, [$vendido, $ativo]));
        $this->assertSame(0, Anuncio::count(), 'recusa_sem_anuncio_parcial');

        $anuncio = $anuncios->publicar($usuario, $fazenda, 4500.00, [$ativo, $ativo]);
        $this->assertSame([$ativo], $anuncio->animais()->pluck('animais.id')->all(), 'id_repetido_entra_uma_vez');
    }

    /**
     * INV-031 — decisão do produtor (27/10/2026, opção A): editar uma parcela
     * para menos cria a parcela da diferença, e a conta nunca vira "paga"
     * sem estar.
     */
    public function test_editar_parcela_para_menos_nunca_deixa_a_soma_abaixo_do_total_inv031(): void
    {
        [$usuario, $obrigacao, $forma] = $this->folhaDeTresMil();
        $formas = app(FormaPagamentoService::class);

        $formas->editar($usuario, $forma->id, ['valor' => 1000.00]);

        $this->assertEqualsWithDelta(3000.00, (float) FormaPagamento::where('obrigacao_financeira_id', $obrigacao->id)->sum('valor'), 0.01, 'soma_das_formas_igual_ao_total');
        $diferenca = FormaPagamento::where('obrigacao_financeira_id', $obrigacao->id)->where('id', '!=', $forma->id)->sole();
        $this->assertSame('Diferença de Salário', $diferenca->nome);
        $this->assertEqualsWithDelta(2000.00, (float) $diferenca->valor, 0.01);
        $this->assertSame('dinheiro', $diferenca->unidade);
        $this->assertNull($diferenca->pago_em);
        $this->assertSame('2026-01-31', $diferenca->vencimento->format('Y-m-d'), 'mesmo_vencimento_da_editada');

        $formas->liquidar($usuario, $forma->id);
        $this->assertSame('parcial', $obrigacao->fresh()->status, 'pagar_1000_de_3000_e_parcial_nunca_pago');
    }

    public function test_editar_parcela_sem_mudar_valor_nao_cria_diferenca_inv031(): void
    {
        [$usuario, $obrigacao, $forma] = $this->folhaDeTresMil();

        app(FormaPagamentoService::class)->editar($usuario, $forma->id, ['vencimento' => '2026-02-05']);

        $this->assertSame(1, FormaPagamento::where('obrigacao_financeira_id', $obrigacao->id)->count());
    }

    /** Parcela trocada para arroba: o valor em reais só existe na liquidação, então nenhuma diferença é inventada. */
    public function test_trocar_parcela_para_arroba_nao_cria_diferenca_por_cotacao_adivinhada_inv031(): void
    {
        [$usuario, $obrigacao, $forma] = $this->folhaDeTresMil();

        app(FormaPagamentoService::class)->editar($usuario, $forma->id, ['unidade' => 'arroba', 'valor' => 10]);

        $this->assertSame(1, FormaPagamento::where('obrigacao_financeira_id', $obrigacao->id)->count());
    }

    /** @return array{0: int, 1: ObrigacaoFinanceira, 2: FormaPagamento} [usuario_id, obrigação do salário, forma única de R$3.000] */
    private function folhaDeTresMil(): array
    {
        [$fazenda, $usuario] = $this->fazendaComDono('Fazenda Alegria', 'José');
        app(FuncionarioService::class)->contratar($usuario, $fazenda, 'Eddie', 'vaqueiro', 3000.00, '2026-01-01 08:00:00', 'funcionario-1');
        $folha = app(FolhaPagamentoService::class)->gerarMes($usuario, $fazenda, '2026-01')['geradas'][0];
        $obrigacao = ObrigacaoFinanceira::where('folha_pagamento_id', $folha->id)->sole();

        return [$usuario, $obrigacao, FormaPagamento::where('obrigacao_financeira_id', $obrigacao->id)->sole()];
    }

    private function assertRecusa(callable $operacao): void
    {
        try {
            $operacao();
        } catch (DomainException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Era para recusar com DomainException.');
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
