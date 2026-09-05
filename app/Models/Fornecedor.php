<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fornecedor extends Model
{
    protected $table = 'fornecedores';

    protected $fillable = ['nome', 'fazenda_id'];

    public function compras(): HasMany
    {
        return $this->hasMany(Compra::class);
    }

    public function comprasInsumo(): HasMany
    {
        return $this->hasMany(CompraInsumo::class);
    }

    // SCHEMA-CONTRATO-MARKETPLACE.md §1 — nullable: NULL é fornecedor externo
    // (comportamento original, intocado); preenchido representa a Fazenda
    // vendedora numa ponte de Marketplace, sempre reaproveitado via
    // firstOrCreate(), nunca duplicado por Fazenda.
    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }
}
