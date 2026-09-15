<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransferenciaFazenda extends Model
{
    protected $table = 'transferencias_fazenda';

    protected $fillable = ['fazenda_origem_id', 'fazenda_destino_id', 'chave_idempotencia', 'data_transferencia'];

    protected $casts = [
        'data_transferencia' => 'datetime',
    ];

    public function fazendaOrigem(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class, 'fazenda_origem_id');
    }

    public function fazendaDestino(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class, 'fazenda_destino_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(TransferenciaAnimal::class, 'transferencia_id');
    }
}
