<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fazenda extends Model
{
    protected $fillable = ['nome', 'estado', 'titular_id', 'descricao', 'logo_url', 'website', 'raca_principal', 'slug', 'ativo'];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    // SCHEMA-CONTRATO-TITULAR.md §3 - titular_id pode ser sobrescrito
    // (trocar de Titular substitui o anterior, sem historico nesta rodada).
    public function titular(): BelongsTo
    {
        return $this->belongsTo(Titular::class);
    }

    public function papeis(): HasMany
    {
        return $this->hasMany(Papel::class);
    }

    public function animais(): HasMany
    {
        return $this->hasMany(Animal::class);
    }

    public function vendas(): HasMany
    {
        return $this->hasMany(Venda::class);
    }
}
