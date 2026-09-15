<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conta extends Model
{
    protected $table = 'contas';

    protected $fillable = ['titular_id', 'tipo', 'nome', 'chave_idempotencia'];

    public function titular(): BelongsTo
    {
        return $this->belongsTo(Titular::class);
    }

    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class);
    }

    // INV-053 — saldo é sempre a soma dos Lançamentos, nunca uma coluna
    // gravada à parte (mesma disciplina de ObrigacaoFinanceira::status,
    // INV-032).
    protected function saldo(): Attribute
    {
        return Attribute::get(function () {
            $lancamentos = $this->relationLoaded('lancamentos')
                ? $this->lancamentos
                : $this->lancamentos()->get();

            return $lancamentos->reduce(
                fn (float $saldo, Lancamento $l) => $saldo + ($l->tipo === 'entrada' ? (float) $l->valor : -(float) $l->valor),
                0.0
            );
        });
    }
}
