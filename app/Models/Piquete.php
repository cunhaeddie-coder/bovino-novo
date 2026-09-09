<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Piquete extends Model
{
    protected $table = 'piquetes';

    protected $fillable = ['fazenda_id', 'nome', 'dias_descanso'];

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function trocasComoOrigem(): HasMany
    {
        return $this->hasMany(TrocaPiquete::class, 'piquete_origem_id');
    }

    public function trocasComoDestino(): HasMany
    {
        return $this->hasMany(TrocaPiquete::class, 'piquete_destino_id');
    }
}
