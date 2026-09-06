<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ConsumoInsumo extends Model
{
    protected $table = 'consumos_insumo';

    protected $fillable = ['fazenda_id', 'insumo_id', 'quantidade', 'data_consumo', 'chave_idempotencia'];

    protected $casts = [
        'quantidade' => 'decimal:2',
        'data_consumo' => 'datetime',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-CONSUMO-INSUMO.md §4 — mesma disciplina padrão de
        // Venda/Compra/CompraInsumo (INV-026): imutável depois de criado.
        // Diferente de Forma de Pagamento (que representa acordo em
        // aberto) — Consumo é fato já consumado.
        static::updating(function () {
            throw new LogicException(
                'ConsumoInsumo é imutável depois de criado (mesma disciplina de INV-026) — correção deve criar uma nova linha, nunca alterar esta.'
            );
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Insumo::class);
    }
}
