<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fazenda extends Model
{
    protected $fillable = ['nome', 'estado', 'descricao', 'logo_url', 'website', 'raca_principal', 'slug', 'ativo'];

    protected $casts = [
        'ativo' => 'boolean',
    ];

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
