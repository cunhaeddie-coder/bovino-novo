<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * INV-026 / SCHEMA-CONTRATO-VENDA.md §5 — Venda::booted() já bloqueia
 * $model->update()/->save() num registro existente, mas isso NÃO intercepta
 * uma atualização em massa via query builder (Venda::where(...)->update([...])),
 * que não dispara eventos de model. Confirmado por execução real em revisão
 * adversarial (26/08/2026) — sem isto, a imutabilidade "bonita" do model era
 * decoração, contornável por um único método a mais.
 *
 * Risco residual, documentado, não escondido: `DB::table('vendas')->update()`
 * ainda contorna esta camada, porque nunca passa pelo Eloquent — fechar isso
 * por completo exigiria um gatilho no próprio banco (fora do escopo mínimo
 * deste vertical).
 */
class VendaBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException(
            'Venda é imutável depois de criada (INV-026) — correção deve criar uma nova linha, nunca alterar esta (bloqueado a nível de query builder).'
        );
    }

    public function delete()
    {
        throw new LogicException(
            'Venda nunca é excluída (INV-026 estende-se a delete) — o histórico nunca desaparece.'
        );
    }
}
