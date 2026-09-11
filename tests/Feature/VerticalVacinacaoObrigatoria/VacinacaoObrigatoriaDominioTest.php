<?php

namespace Tests\Feature\VerticalVacinacaoObrigatoria;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Fazenda;
use App\Models\Insumo;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\EventoSaudeService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de domínio do Vertical 18 (Vacinação Obrigatória) — nasce de
 * VERTICAL-VACINACAO-OBRIGATORIA.md e SCHEMA-CONTRATO-VACINACAO-
 * OBRIGATORIA.md. Estende EventoSaudeService::registrar() (Vertical 11)
 * com certificado/tipo_vacina opcionais, sem tocar no fato original.
 */
class VacinacaoObrigatoriaDominioTest extends TestCase
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
        $this->insumo = Insumo::create(['fazenda_id' => $this->fazenda, 'nome' => 'Vacina Aftosa', 'quantidade' => 100])->id;
    }

    private function criarAnimais(int $quantidade): array
    {
        return collect(range(1, $quantidade))
            ->map(fn () => Animal::create(['fazenda_id' => $this->fazenda, 'lote_id' => null, 'custo_aquisicao' => 0, 'status' => 'ativo'])->id)
            ->all();
    }

    /** Regressão do Vertical 11 — sem os 2 novos argumentos, comportamento idêntico. */
    public function test_registrar_sem_certificado_e_tipo_vacina_continua_funcionando_igual_ao_vertical_11(): void
    {
        $animais = $this->criarAnimais(2);
        $registro = $this->eventos->registrar($this->jose, $this->fazenda, $animais, $this->insumo, 10.0, 'vermifugo', '2026-01-01 08:00:00', 'evento-comum');

        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertNull($registro['evento']->certificado);
        $this->assertNull($registro['evento']->tipo_vacina);
    }

    /** LAB real: vacina fiscalizável carrega certificado e tipo declarado. */
    public function test_registrar_vacina_fiscalizavel_grava_certificado_e_tipo_vacina(): void
    {
        $animais = $this->criarAnimais(3);
        $registro = $this->eventos->registrar(
            $this->jose, $this->fazenda, $animais, $this->insumo, 30.0, 'vacina aftosa',
            '2026-01-01 08:00:00', 'evento-fiscalizavel', 'CERT-2026-042', 'aftosa'
        );

        $this->assertFalse($registro['reenvio_detectado']);
        $this->assertSame('CERT-2026-042', $registro['evento']->certificado);
        $this->assertSame('aftosa', $registro['evento']->tipo_vacina);

        $evento = EventoDominio::where('fazenda_id', $this->fazenda)->where('tipo', 'evento_saude_registrado')->first();
        $this->assertSame('CERT-2026-042', $evento->payload['certificado']);
        $this->assertSame('aftosa', $evento->payload['tipo_vacina']);
    }

    /** tipo_vacina é texto livre — achado real: exigência varia por região/estado, sem vocabulário fechado. */
    public function test_tipo_vacina_aceita_qualquer_texto_nao_e_vocabulario_fechado(): void
    {
        $animais = $this->criarAnimais(1);
        $registro = $this->eventos->registrar(
            $this->jose, $this->fazenda, $animais, $this->insumo, 5.0, 'vacina clostridiose',
            '2026-01-01 08:00:00', 'evento-regional', 'CERT-SP-9', 'clostridiose (exigência SP)'
        );

        $this->assertSame('clostridiose (exigência SP)', $registro['evento']->tipo_vacina);
    }

    public function test_certificado_sem_tipo_vacina_e_recusado(): void
    {
        $animais = $this->criarAnimais(1);

        $this->assertThrows(
            fn () => $this->eventos->registrar($this->jose, $this->fazenda, $animais, $this->insumo, 5.0, 'vacina', '2026-01-01 08:00:00', 'evento-a', 'CERT-1', null),
            DomainException::class
        );
    }

    public function test_tipo_vacina_sem_certificado_e_recusado(): void
    {
        $animais = $this->criarAnimais(1);

        $this->assertThrows(
            fn () => $this->eventos->registrar($this->jose, $this->fazenda, $animais, $this->insumo, 5.0, 'vacina', '2026-01-01 08:00:00', 'evento-b', null, 'aftosa'),
            DomainException::class
        );
    }

    /** String em branco conta como ausente — mesma disciplina de trim() já usada em descricao/chave_idempotencia. */
    public function test_certificado_em_branco_com_tipo_vacina_preenchido_e_recusado(): void
    {
        $animais = $this->criarAnimais(1);

        $this->assertThrows(
            fn () => $this->eventos->registrar($this->jose, $this->fazenda, $animais, $this->insumo, 5.0, 'vacina', '2026-01-01 08:00:00', 'evento-c', '   ', 'aftosa'),
            DomainException::class
        );
    }
}
