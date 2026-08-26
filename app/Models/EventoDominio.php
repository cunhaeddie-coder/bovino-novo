<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoDominio extends Model
{
    protected $table = 'eventos_dominio';

    protected $fillable = ['tipo', 'fazenda_id', 'chave_idempotencia', 'payload', 'status_consequencia'];

    protected $casts = [
        'payload' => 'array',
    ];

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }
}
