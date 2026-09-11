<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Incendio extends Model
{
    protected $fillable = ['fazenda_id', 'piquete_id', 'data_incendio', 'chave_idempotencia'];

    protected $casts = [
        'data_incendio' => 'datetime',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-INCENDIO.md §7 — mesma disciplina padrão de
        // Morte/Nascimento/Troca de Piquete/Pesagem (INV-026): imutável
        // desde a criação.
        static::updating(function () {
            throw new LogicException(
                'Incendio é imutável depois de registrado (mesma disciplina de INV-026) — correção deve criar um novo registro, nunca alterar este.'
            );
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function piquete(): BelongsTo
    {
        return $this->belongsTo(Piquete::class);
    }
}
