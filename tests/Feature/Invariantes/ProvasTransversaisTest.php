<?php

namespace Tests\Feature\Invariantes;

use App\Models\Animal;
use App\Models\Anuncio;
use App\Models\ComissaoPlataforma;
use App\Models\Fazenda;
use App\Models\Fornecedor;
use App\Models\Insumo;
use App\Models\Kyc;
use App\Models\Lote;
use App\Models\ObrigacaoFinanceira;
use App\Models\Papel;
use App\Models\Titular;
use App\Models\Usuario;
use App\Models\Venda;
use App\Services\AuthService;
use App\Services\CompraInsumoService;
use App\Services\ConfiguracaoPlataformaService;
use App\Services\ConsumoInsumoService;
use App\Services\EventoSaudeService;
use App\Services\GtaService;
use App\Services\MotoristaService;
use App\Services\NegociacaoService;
use App\Services\SeparacaoVendaService;
use App\Services\VendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Provas que faltavam no documento de invariantes (roteiro até a publicação,
 * etapa 1, item 5 — 27/10/2026). Cada uma tinha só parte da prova espalhada
 * pelos verticais; aqui o comportamento que a invariante promete é exercitado
 * de ponta a ponta, num teste só.
 */
class ProvasTransversaisTest extends TestCase
{
    use RefreshDatabase;

    /**
     * INV-003 — o mesmo cenário (3 de um lote de 10 animais que custou
     * R$10.000, vendidos por R$4.500) pelos 4 canais de venda produz
     * exatamente as mesmas consequências: baixa de rebanho, custo do lote,
     * CPV, dedução fiscal, receita líquida e valor a receber.
     */
    public function test_mesma_venda_pelos_4_canais_tem_as_mesmas_consequencias_inv003(): void
    {
        $direta = $this->venderPorCanal('direta');

        foreach (['gta', 'separacao', 'marketplace'] as $canal) {
            $this->assertEquals($direta, $this->venderPorCanal($canal), "canal_{$canal}_diverge_da_venda_direta");
        }

        $this->assertSame(7, $direta['lote_qtd']);
        $this->assertEqualsWithDelta(7000.00, $direta['lote_custo'], 0.01);
        $this->assertEqualsWithDelta(3000.00, $direta['cpv'], 0.01);
        $this->assertEqualsWithDelta(4500.00, $direta['a_receber'], 0.01);
        $this->assertSame(['vendido', 'vendido', 'vendido'], $direta['status_vendidos']);
    }

    /**
     * INV-005 — a compra de um insumo aparece uma única vez no financeiro,
     * mesmo depois de o insumo ser consumido e aplicado em eventos de saúde.
     */
    public function test_compra_de_insumo_aparece_uma_unica_vez_no_financeiro_inv005(): void
    {
        [$fazenda, $usuario] = $this->fazendaComDono('Fazenda Alegria', 'José');
        $fornecedor = Fornecedor::create(['nome' => 'Casa Agropecuária'])->id;

        $compra = app(CompraInsumoService::class)->registrar($usuario, $fazenda, $fornecedor, [
            ['insumo_novo' => ['nome' => 'Vermífugo Dose Única'], 'quantidade' => 20, 'valor_unitario' => 32.00],
        ], '2026-01-05', 'compra-vermifugo')['compra'];
        $vermifugo = Insumo::where('fazenda_id', $fazenda)->where('nome', 'Vermífugo Dose Única')->firstOrFail()->id;
        $animais = $this->criarAnimaisAvulsos($fazenda, 2);

        app(ConsumoInsumoService::class)->registrar($usuario, $fazenda, $vermifugo, 5, '2026-01-10 08:00:00', 'consumo-1');
        app(EventoSaudeService::class)->registrar($usuario, $fazenda, $animais, $vermifugo, 2, 'vermifugação', '2026-01-12 08:00:00', 'saude-1');
        app(EventoSaudeService::class)->registrar($usuario, $fazenda, $animais, $vermifugo, 3, 'vermifugação', '2026-02-12 08:00:00', 'saude-2');

        $obrigacoes = ObrigacaoFinanceira::where('fazenda_id', $fazenda)->get();
        $this->assertCount(1, $obrigacoes, 'uma_unica_despesa_pela_compra');
        $this->assertSame($compra->id, $obrigacoes->first()->compra_insumo_id);
        $this->assertEqualsWithDelta(640.00, (float) $obrigacoes->first()->valor, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) Insumo::find($vermifugo)->quantidade, 0.01, 'estoque_baixou_10_de_20');
    }

    /**
     * INV-045 — a comissão da plataforma existe para o frete contratado, mas
     * nenhuma resposta da API para a Fazenda a mostra (nem a do contrato,
     * nem a listagem de ordens).
     */
    public function test_fazenda_nunca_ve_a_comissao_da_plataforma_inv045(): void
    {
        $fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $usuario = app(AuthService::class)->registrar('José', 'jose@fazenda.com', null, 'senha123');
        Papel::create(['usuario_id' => $usuario->id, 'fazenda_id' => $fazenda, 'papel' => 'dono']);
        $token = $this->postJson('/api/auth/login', ['identificador' => 'jose@fazenda.com', 'senha' => 'senha123'])->json('token');

        $administrador = Usuario::create(['nome' => 'Admin', 'eh_administrador' => true])->id;
        app(ConfiguracaoPlataformaService::class)->definirComissaoFrete($administrador, 10.0);
        $motorista = app(MotoristaService::class)->cadastrar(Usuario::create(['nome' => 'Motorista Carlos'])->id, '000.000.000-00');
        app(MotoristaService::class)->aprovar($administrador, $motorista->id);

        $contrato = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/ordens-frete/contratar-direto', [
            'fazenda_id' => $fazenda, 'motorista_id' => $motorista->id, 'valor_frete' => 1000, 'chave_idempotencia' => 'frete-1',
        ]);
        $contrato->assertStatus(201);
        $this->assertSame(1, ComissaoPlataforma::count(), 'a_comissao_existe');

        $lista = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/ordens-frete?fazenda_id={$fazenda}");
        $lista->assertOk();

        foreach ([$contrato, $lista] as $resposta) {
            $this->assertStringNotContainsString('comissao', $resposta->getContent());
            $this->assertStringNotContainsString('percentual_aplicado', $resposta->getContent());
        }
    }

    /** @return array{lote_qtd: int, lote_custo: float, cpv: float, deducao_fiscal: float, receita_liquida: float, a_receber: float, status_vendidos: string[]} */
    private function venderPorCanal(string $canal): array
    {
        [$fazenda, $usuario] = $this->fazendaComDono("Fazenda {$canal}", "Dono {$canal}");
        $lote = Lote::create(['fazenda_id' => $fazenda, 'qtd_animais' => 10, 'custo_aquisicao' => 10000.00])->id;
        $animais = collect(range(1, 10))
            ->map(fn () => Animal::create(['fazenda_id' => $fazenda, 'lote_id' => $lote, 'status' => 'ativo'])->id)
            ->all();
        $vendidos = array_slice($animais, 0, 3);

        $vendaId = match ($canal) {
            'direta' => app(VendaService::class)->registrar($usuario, $fazenda, $vendidos, 4500.00, '2026-01-01 10:00:00', 'venda')['venda']->id,
            'gta' => $this->concluirGta($usuario, $fazenda, $vendidos),
            'separacao' => $this->concluirSeparacao($usuario, $fazenda, $vendidos),
            'marketplace' => $this->venderNoMarketplace($usuario, $fazenda, $vendidos),
        };

        $venda = Venda::findOrFail($vendaId);
        $loteDepois = Lote::findOrFail($lote);

        return [
            'lote_qtd' => $loteDepois->qtd_animais,
            'lote_custo' => round((float) $loteDepois->custo_aquisicao, 2),
            'cpv' => round((float) $venda->cpv, 2),
            'deducao_fiscal' => round((float) $venda->deducao_fiscal, 2),
            'receita_liquida' => round((float) $venda->receita_liquida, 2),
            'a_receber' => round((float) ObrigacaoFinanceira::where('venda_id', $venda->id)->sole()->valor, 2),
            'status_vendidos' => Animal::whereIn('id', $vendidos)->orderBy('id')->pluck('status')->all(),
        ];
    }

    private function concluirGta(int $usuario, int $fazenda, array $animais): int
    {
        $gtas = app(GtaService::class);
        $gta = $gtas->registrar($usuario, $fazenda, $animais, 'Frigorífico Central', 3, 4500.00, '2026-01-01 08:00:00', 'gta')['gta'];

        return $gtas->concluir($usuario, $gta->id)['venda']->id;
    }

    private function concluirSeparacao(int $usuario, int $fazenda, array $animais): int
    {
        $separacoes = app(SeparacaoVendaService::class);
        $separacao = $separacoes->registrar($usuario, $fazenda, $animais, 4500.00, '2026-01-01 08:00:00', 'separacao')['separacao'];

        return $separacoes->concluir($usuario, $separacao->id)['venda']->id;
    }

    private function venderNoMarketplace(int $vendedor, int $fazenda, array $animais): int
    {
        [$fazendaCompradora, $compradora] = $this->fazendaComDono('Sítio Comprador', 'Maria');
        $titular = Titular::create(['documento' => uniqid('doc'), 'tipo_documento' => 'cpf']);
        Fazenda::where('id', $fazendaCompradora)->update(['titular_id' => $titular->id]);
        Kyc::create(['titular_id' => $titular->id, 'status' => 'aprovado', 'verificado_em' => now()]);

        $anuncio = Anuncio::create(['fazenda_id' => $fazenda, 'preco_total' => 4500, 'status' => 'ativo', 'publicado_em' => '2026-01-01']);
        $anuncio->animais()->attach($animais);

        $negociacoes = app(NegociacaoService::class);
        $negociacao = $negociacoes->propor($compradora, $anuncio->id, $fazendaCompradora, 4500, 'proposta')['negociacao'];
        $negociacoes->aceitar($vendedor, $negociacao->id);

        return $negociacoes->confirmarVendedor($vendedor, $negociacao->id)['venda']->id;
    }

    /** @return array{0: int, 1: int} [fazenda_id, usuario_id] */
    private function fazendaComDono(string $fazenda, string $dono): array
    {
        $fazendaId = Fazenda::create(['nome' => $fazenda])->id;
        $usuarioId = Usuario::create(['nome' => $dono])->id;
        Papel::create(['usuario_id' => $usuarioId, 'fazenda_id' => $fazendaId, 'papel' => 'dono']);

        return [$fazendaId, $usuarioId];
    }

    private function criarAnimaisAvulsos(int $fazenda, int $quantidade): array
    {
        return collect(range(1, $quantidade))
            ->map(fn () => Animal::create(['fazenda_id' => $fazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id)
            ->all();
    }
}
