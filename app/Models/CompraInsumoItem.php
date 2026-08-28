<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompraInsumoItem extends Model
{
    protected $table = 'compra_insumo_itens';

    protected $fillable = ['compra_insumo_id', 'insumo_id', 'quantidade', 'valor_unitario'];

    protected $casts = [
        'quantidade' => 'decimal:2',
        'valor_unitario' => 'decimal:2',
    ];

    public function compraInsumo(): BelongsTo
    {
        return $this->belongsTo(CompraInsumo::class);
    }

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Insumo::class);
    }
}
