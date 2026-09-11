<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Pesagem extends Model
{
    protected $table = 'pesagens';

    protected $fillable = ['fazenda_id', 'animal_id', 'peso', 'data_pesagem', 'chave_idempotencia'];

    protected $casts = [
        'data_pesagem' => 'datetime',
        'peso' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-PESAGEM.md §7 — mesma disciplina padrão de
        // Morte/Nascimento/Produção Leiteira (INV-026): imutável desde a
        // criação.
        static::updating(function () {
            throw new LogicException(
                'Pesagem é imutável depois de registrada (mesma disciplina de INV-026) — correção deve criar um novo registro, nunca alterar este.'
            );
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }
}
