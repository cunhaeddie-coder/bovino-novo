<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProducaoLeiteira extends Model
{
    protected $table = 'producoes_leiteiras';

    protected $fillable = ['fazenda_id', 'animal_id', 'data_producao', 'quantidade_total', 'quantidade_vendida', 'chave_idempotencia'];

    protected $casts = [
        'data_producao' => 'datetime',
        'quantidade_total' => 'decimal:2',
        'quantidade_vendida' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // INV-039 (revista em 28/10/2026, sem o leite pro bezerro): o leite
        // vendido nunca excede o total ordenhado.
        $regraSoma = function (self $producao) {
            if (round((float) $producao->quantidade_vendida, 2) > round((float) $producao->quantidade_total, 2)) {
                throw new LogicException(
                    'ProducaoLeiteira.quantidade_vendida não pode exceder quantidade_total (INV-039).'
                );
            }
        };

        // SCHEMA-CONTRATO-PRODUCAO-LEITEIRA.md §6 — mesma disciplina padrão
        // de Morte/Nascimento/Evento de Saúde (INV-026): imutável desde a
        // criação.
        static::creating($regraSoma);
        static::updating(function () {
            throw new LogicException(
                'ProducaoLeiteira é imutável depois de registrada (mesma disciplina de INV-026) — correção deve criar um novo registro, nunca alterar este.'
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
