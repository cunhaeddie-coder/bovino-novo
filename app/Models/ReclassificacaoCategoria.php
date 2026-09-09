<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ReclassificacaoCategoria extends Model
{
    protected $table = 'reclassificacoes_categoria';

    protected $fillable = ['fazenda_id', 'animal_ids', 'categoria_nova', 'chave_idempotencia'];

    protected $casts = [
        'animal_ids' => 'array',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-RECLASSIFICACAO.md §5 — mesma disciplina padrão
        // de Morte/Nascimento/Evento de Saúde (INV-026): imutável desde a
        // criação.
        static::updating(function () {
            throw new LogicException(
                'ReclassificacaoCategoria é imutável depois de registrada (mesma disciplina de INV-026) — correção deve criar uma nova reclassificação, nunca alterar esta.'
            );
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }
}
