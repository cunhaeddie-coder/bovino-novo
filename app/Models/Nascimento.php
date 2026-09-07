<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Nascimento extends Model
{
    protected $table = 'nascimentos';

    protected $fillable = ['fazenda_id', 'animal_ids', 'data_nascimento', 'chave_idempotencia'];

    protected $casts = [
        'animal_ids' => 'array',
        'data_nascimento' => 'datetime',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-NASCIMENTO.md §6 — mesma disciplina padrão de
        // Morte/Consumo de Insumo (INV-026): imutável desde a criação, fato
        // único, sem lifecycle.
        static::updating(function () {
            throw new LogicException(
                'Nascimento é imutável depois de registrado (mesma disciplina de INV-026) — correção deve criar uma nova linha, nunca alterar esta.'
            );
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }
}
