<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Insumo extends Model
{
    protected $fillable = ['fazenda_id', 'nome', 'quantidade', 'valor_referencia'];

    protected $casts = [
        'quantidade' => 'decimal:2',
        'valor_referencia' => 'decimal:2',
    ];

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function itensDeCompra(): HasMany
    {
        return $this->hasMany(CompraInsumoItem::class);
    }
}
