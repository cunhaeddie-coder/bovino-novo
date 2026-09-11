<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MarcacaoCio extends Model
{
    protected $table = 'marcacoes_cio';

    protected $fillable = ['fazenda_id', 'vaca_id', 'rufiao_id', 'data_marcacao', 'chave_idempotencia'];

    protected $casts = [
        'data_marcacao' => 'datetime',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-MARCACAO-CIO-PRENHEZ.md §7 — mesma disciplina
        // padrão de Morte/Nascimento/Evento de Saúde (INV-026): imutável
        // desde a criação.
        static::updating(function () {
            throw new LogicException(
                'MarcacaoCio é imutável depois de registrada (mesma disciplina de INV-026) — correção deve criar um novo registro, nunca alterar este.'
            );
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function vaca(): BelongsTo
    {
        return $this->belongsTo(Animal::class, 'vaca_id');
    }

    public function rufiao(): BelongsTo
    {
        return $this->belongsTo(Animal::class, 'rufiao_id');
    }
}
