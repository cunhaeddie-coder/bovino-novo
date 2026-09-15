<?php

namespace Tests\Feature\VerticalNutricao;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Insumo;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\EventoSaudeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 31 (Nutrição) — nasce de VERTICAL-NUTRICAO.md
 * e SCHEMA-CONTRATO-NUTRICAO.md. Estende EventoSaudeService::registrar()
 * (Vertical 11, já estendido pelo Vertical 18) com epoca opcional, sem
 * tocar no fato original nem no guard certificado/tipo_vacina.
 */
class NutricaoDominioTest extends TestCase
{
    use RefreshDatabase;

    private EventoSaudeService $eventos;

    private int $fazenda;

    private int $jose;

    private int $insumo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventos = app(EventoSaudeService::class);
        $this->fazenda = Fazenda::create(['nome' => 'Fazenda Alegria'])->id;
        $this->jose = Usuario::create(['nome' => 'José'])->id;
        Papel::create(['usuario_id' => $this->jose, 'fazenda_id' => $this->fazenda, 'papel' => 'dono']);
        $this->insumo = Insumo::create(['fazenda_id' => $this->fazenda, 'nome' => 'Sal Proteinado 30kg', 'quantidade' => 100])->id;
    }

    private function criarAnimais(int $quantidade): array
    {
        return collect(range(1, $quantidade))
            ->map(fn () => Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id)
            ->all();
    }

    /** Regressão dos Verticais 11/18 — sem o novo argumento, comportamento idêntico. */
    public function test_registrar_sem_epoca_continua_funcionando_igual_aos_verticais_anteriores(): void
    {
        $animais = $this->criarAnimais(2);
        $registro = $this->eventos->registrar($this->jose, $this->fazenda, $animais, $this->insumo, 10.0, 'vermifugo', '2026-01-01 08:00:00', 'evento-comum');

        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertNull($registro['evento']->epoca);
    }

    /** LAB real (LAB-FA-023): plano nutricional carrega a época do ano. */
    public function test_registrar_plano_nutricional_grava_epoca(): void
    {
        $animais = $this->criarAnimais(160);
        $registro = $this->eventos->registrar(
            $this->jose, $this->fazenda, $animais, $this->insumo, 80.0, 'sal proteinado',
            '2026-06-01 08:00:00', 'evento-seca', epoca: 'seca'
        );

        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertSame('seca', $registro['evento']->epoca);

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'evento_saude_registrado')->first();
        $this->assertSame('seca', $evento->payload['epoca']);
    }

    /** epoca é texto livre — mesma disciplina do achado do Vertical 18 (tipo_vacina), sem vocabulário fechado. */
    public function test_epoca_aceita_qualquer_texto_nao_e_vocabulario_fechado(): void
    {
        $animais = $this->criarAnimais(1);
        $registro = $this->eventos->registrar(
            $this->jose, $this->fazenda, $animais, $this->insumo, 5.0, 'sal mineral',
            '2026-01-01 08:00:00', 'evento-regional', epoca: 'chuvas (safra do Sul)'
        );

        $this->assertSame('chuvas (safra do Sul)', $registro['evento']->epoca);
    }

    /** epoca não exige nenhum campo par — diferente do guard certificado/tipo_vacina do Vertical 18. */
    public function test_epoca_sozinha_nao_e_recusada(): void
    {
        $animais = $this->criarAnimais(1);
        $registro = $this->eventos->registrar(
            $this->jose, $this->fazenda, $animais, $this->insumo, 5.0, 'sal proteinado',
            '2026-01-01 08:00:00', 'evento-sem-par', epoca: 'aguas'
        );

        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertSame('aguas', $registro['evento']->epoca);
    }

    /** epoca e certificado/tipo_vacina (Vertical 18) convivem sem interferência mútua. */
    public function test_epoca_convive_com_certificado_tipo_vacina_na_mesma_chamada(): void
    {
        $animais = $this->criarAnimais(1);
        $registro = $this->eventos->registrar(
            $this->jose, $this->fazenda, $animais, $this->insumo, 5.0, 'vacina + reforço mineral',
            '2026-01-01 08:00:00', 'evento-combinado', 'CERT-2026-099', 'aftosa', 'aguas'
        );

        $this->assertSame('CERT-2026-099', $registro['evento']->certificado);
        $this->assertSame('aftosa', $registro['evento']->tipo_vacina);
        $this->assertSame('aguas', $registro['evento']->epoca);
    }
}
