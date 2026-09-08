<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProducaoLeiteira extends Model
{
    protected $table = 'producoes_leiteiras';

    protected $fillable = ['fazenda_id', 'animal_id', 'data_producao', 'quantidade_total', 'quantidade_vendida', 'quantidade_bezerro', 'chave_idempotencia'];

    protected $casts = [
        'data_producao' => 'datetime',
        'quantidade_total' => 'decimal:2',
        'quantidade_vendida' => 'decimal:2',
        'quantidade_bezerro' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-PRODUCAO-LEITEIRA.md §3 — INV-039: a soma do
        // leite vendido e do leite pro bezerro nunca excede o total
        // produzido.
        $regraSoma = function (self $producao) {
            $soma = round((float) $producao->quantidade_vendida + (float) $producao->quantidade_bezerro, 2);
            if ($soma > round((float) $producao->quantidade_total, 2)) {
                throw new LogicException(
                    'ProducaoLeiteira.quantidade_vendida + quantidade_bezerro não pode exceder quantidade_total (INV-039).'
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
