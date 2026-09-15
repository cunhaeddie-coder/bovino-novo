<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Usuario extends Model
{
    protected $fillable = ['nome', 'eh_administrador'];

    protected $casts = [
        'eh_administrador' => 'boolean',
    ];

    public function papeis(): HasMany
    {
        return $this->hasMany(Papel::class);
    }

    /** INV-029 — a única pergunta que decide qualquer acesso (Spike 005/006). */
    public function temRelacaoComFazenda(int $fazendaId): bool
    {
        return $this->papeis()->where('fazenda_id', $fazendaId)->exists();
    }

    // SCHEMA-CONTRATO-CARTEIRA.md §6 — Titular não tem Papel próprio; a
    // relação sempre passa por pelo menos uma Fazenda daquele Titular.
    public function temRelacaoComTitular(int $titularId): bool
    {
        return Fazenda::where('titular_id', $titularId)
            ->whereIn('id', $this->papeis()->pluck('fazenda_id'))
            ->exists();
    }
}
