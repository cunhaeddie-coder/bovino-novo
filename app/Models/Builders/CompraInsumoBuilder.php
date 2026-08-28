<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

// Mesma proteção de App\Models\Builders\CompraBuilder/VendaBuilder, mesmo
// motivo: um guard em evento de model (CompraInsumo::booted()) não
// intercepta update em massa via query builder. Risco residual, documentado,
// não escondido: DB::table('compras_insumo')->update() ainda contorna esta
// camada — fechar isso por completo exige um gatilho no próprio banco, fora
// do escopo mínimo deste vertical.
class CompraInsumoBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException(
            'Compra de Insumo é imutável depois de criada (mesmo padrão de INV-026) — correção deve criar uma nova linha, nunca alterar esta (bloqueado a nível de query builder).'
        );
    }

    public function delete()
    {
        throw new LogicException(
            'Compra de Insumo nunca é excluída — o histórico nunca desaparece.'
        );
    }
}
