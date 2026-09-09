<?php

namespace Tests\Feature\VerticalRotacaoPastagem;

use App\Models\Fazenda;
use App\Models\Lote;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\RotacaoPastagemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** INV-029 — isolamento entre Fazendas, mesmo padrão dos 14 verticais anteriores. */
class IsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_de_outra_fazenda_nao_ve_a_troca(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $lote = Lote::create(['fazenda_id' => $fazendaA, 'qtd_animais' => 10, 'custo_aquisicao' => 20000])->id;

        $service = app(RotacaoPastagemService::class);
        $registro = $service->registrar($jose, $fazendaA, $lote, null, null, ['nome' => 'Piquete 1', 'dias_descanso' => 20], '2026-01-01 08:00:00', 'troca-1');

        $this->assertNull($service->buscar($mariazinha, $registro['troca']->id));
        $this->assertNotNull($service->buscar($jose, $registro['troca']->id));
    }

    public function test_chaves_iguais_em_fazendas_diferentes_nao_sao_reenvio(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $loteA = Lote::create(['fazenda_id' => $fazendaA, 'qtd_animais' => 10, 'custo_aquisicao' => 20000])->id;
        $loteB = Lote::create(['fazenda_id' => $fazendaB, 'qtd_animais' => 8, 'custo_aquisicao' => 16000])->id;

        $service = app(RotacaoPastagemService::class);
        $resultadoA = $service->registrar($jose, $fazendaA, $loteA, null, null, ['nome' => 'Piquete 1', 'dias_descanso' => 20], '2026-01-01 08:00:00', 'chave-igual');
        $resultadoB = $service->registrar($mariazinha, $fazendaB, $loteB, null, null, ['nome' => 'Piquete 1', 'dias_descanso' => 15], '2026-01-01 08:00:00', 'chave-igual');

        $this->assertFalse($resultadoB['reenvio_detectado']);
        $this->assertNotSame($resultadoA['troca']->id, $resultadoB['troca']->id);
    }

    public function test_piquete_do_mesmo_nome_em_fazendas_diferentes_nao_colide(): void
    {
        $fazendaA = Fazenda::create(['nome' => 'A'])->id;
        $fazendaB = Fazenda::create(['nome' => 'B'])->id;
        $jose = Usuario::create(['nome' => 'José'])->id;
        $mariazinha = Usuario::create(['nome' => 'Mariazinha'])->id;
        Papel::create(['usuario_id' => $jose, 'fazenda_id' => $fazendaA, 'papel' => 'dono']);
        Papel::create(['usuario_id' => $mariazinha, 'fazenda_id' => $fazendaB, 'papel' => 'dono']);
        $loteA = Lote::create(['fazenda_id' => $fazendaA, 'qtd_animais' => 10, 'custo_aquisicao' => 20000])->id;
        $loteB = Lote::create(['fazenda_id' => $fazendaB, 'qtd_animais' => 8, 'custo_aquisicao' => 16000])->id;

        $service = app(RotacaoPastagemService::class);
        $resultadoA = $service->registrar($jose, $fazendaA, $loteA, null, null, ['nome' => 'Piquete X', 'dias_descanso' => 20], '2026-01-01 08:00:00', 'a1');
        $resultadoB = $service->registrar($mariazinha, $fazendaB, $loteB, null, null, ['nome' => 'Piquete X', 'dias_descanso' => 20], '2026-01-01 08:00:00', 'b1');

        $this->assertFalse($resultadoB['reenvio_detectado']);
        $this->assertNotSame($resultadoA['troca']->piquete_destino_id, $resultadoB['troca']->piquete_destino_id);
    }
}
