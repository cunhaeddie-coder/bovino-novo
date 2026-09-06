<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Morte extends Model
{
    protected $table = 'mortes';

    protected $fillable = ['fazenda_id', 'animal_ids', 'causa', 'data_morte', 'chave_idempotencia'];

    protected $casts = [
        'animal_ids' => 'array',
        'data_morte' => 'datetime',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-MORTE.md §6 — mesma disciplina padrão de Consumo
        // de Insumo (INV-026): imutável desde a criação, fato único, sem
        // lifecycle (diferente de GTA).
        static::updating(function () {
            throw new LogicException(
                'Morte é imutável depois de registrada (mesma disciplina de INV-026) — correção deve criar uma nova linha, nunca alterar esta.'
            );
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }
}
