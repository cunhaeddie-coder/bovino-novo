<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Animal extends Model
{
    protected $table = 'animais';

    protected $fillable = ['fazenda_id', 'lote_id', 'status', 'data_saida'];

    protected $casts = [
        'data_saida' => 'date',
    ];

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }
}
