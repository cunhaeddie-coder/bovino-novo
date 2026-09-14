<?php

namespace App\Services;

use App\Models\Fazenda;
use App\Models\Usuario;
use DomainException;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * O núcleo do Vertical 21 (Inteligência de Mercado — Cotações Realizadas),
 * nascido de VERTICAL-INTELIGENCIA-MERCADO.md, traduzindo
 * SCHEMA-CONTRATO-INTELIGENCIA-MERCADO.md pra código real. Diferente de
 * todo vertical anterior, não escreve nenhum fato novo — é consulta pura,
 * agregada sobre TODAS as Fazendas (dado da plataforma, nunca de uma
 * Fazenda só), sempre autenticada, sempre atrás da camada de capacidade
 * (Pennant, DECISOES-ABERTAS.md item 6 — primeira aplicação real deste
 * mecanismo neste projeto).
 */
class IntelligenciaMercadoService
{
    // Placeholder (VERTICAL-INTELIGENCIA-MERCADO.md §8): grade comercial de
    // planos ainda não fechada em nenhum documento. Nenhuma Fazenda tem essa
    // feature ativa por padrão — precisa ser concedida explicitamente
    // (Feature::activate(), fora do escopo deste vertical).
    public const FEATURE = 'inteligencia-mercado';

    // INV-041 — combinação raça/estado com menos de 3 vendas confirmadas
    // nunca aparece na resposta.
    private const MINIMO_VENDAS = 3;

    private const JANELA_DIAS = 30;

    public function cotacoesRealizadas(int $usuarioId, int $fazendaId): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        $fazenda = Fazenda::findOrFail($fazendaId);
        if (! Feature::for($fazenda)->active(self::FEATURE)) {
            throw new DomainException(
                "Fazenda #{$fazendaId} não tem acesso a Inteligência de Mercado no plano atual."
            );
        }

        return DB::table('venda_animal')
            ->join('vendas', 'vendas.id', '=', 'venda_animal.venda_id')
            ->join('animais', 'animais.id', '=', 'venda_animal.animal_id')
            ->join('fazendas', 'fazendas.id', '=', 'vendas.fazenda_id')
            // Só a linha vigente de cada Venda: o original quando nunca
            // corrigido, ou a correção quando existir — nunca o original já
            // superado (SCHEMA-CONTRATO-INTELIGENCIA-MERCADO.md §4).
            ->whereNotIn('vendas.id', function ($query) {
                $query->select('venda_original_id')
                    ->from('vendas')
                    ->whereNotNull('venda_original_id');
            })
            ->where('vendas.created_at', '>=', now()->subDays(self::JANELA_DIAS))
            ->whereNotNull('animais.raca')
            ->whereNotNull('fazendas.estado')
            ->selectRaw(
                'animais.raca as raca, fazendas.estado as estado, '.
                'AVG(vendas.valor_bruto / (SELECT COUNT(*) FROM venda_animal va2 WHERE va2.venda_id = vendas.id)) as preco_medio, '.
                'COUNT(DISTINCT vendas.id) as vendas_confirmadas'
            )
            ->groupBy('animais.raca', 'fazendas.estado')
            ->havingRaw('COUNT(DISTINCT vendas.id) >= ?', [self::MINIMO_VENDAS])
            ->orderByDesc('vendas_confirmadas')
            ->get()
            ->map(fn ($linha) => [
                'raca' => $linha->raca,
                'estado' => $linha->estado,
                'preco_medio' => round((float) $linha->preco_medio, 2),
                'vendas_confirmadas' => (int) $linha->vendas_confirmadas,
            ])
            ->all();
    }

    private function garantirRelacaoComFazenda(int $usuarioId, int $fazendaId): void
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($fazendaId)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com a Fazenda {$fazendaId}.");
        }
    }
}
