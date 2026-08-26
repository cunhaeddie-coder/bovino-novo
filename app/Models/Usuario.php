<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Usuario extends Model
{
    protected $fillable = ['nome'];

    public function papeis(): HasMany
    {
        return $this->hasMany(Papel::class);
    }

    /** INV-029 — a única pergunta que decide qualquer acesso (Spike 005/006). */
    public function temRelacaoComFazenda(int $fazendaId): bool
    {
        return $this->papeis()->where('fazenda_id', $fazendaId)->exists();
    }
}
