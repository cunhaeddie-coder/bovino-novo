<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lote extends Model
{
    protected $fillable = ['fazenda_id', 'qtd_animais', 'custo_aquisicao'];

    protected $casts = [
        'custo_aquisicao' => 'decimal:2',
    ];

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function animais(): HasMany
    {
        return $this->hasMany(Animal::class);
    }
}
