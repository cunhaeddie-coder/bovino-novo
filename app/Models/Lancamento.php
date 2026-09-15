<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Lancamento extends Model
{
    protected $table = 'lancamentos';

    protected $fillable = ['conta_id', 'tipo', 'valor', 'data_lancamento', 'descricao', 'origem', 'forma_pagamento_id', 'chave_idempotencia'];

    protected $casts = [
        'valor' => 'decimal:2',
        'data_lancamento' => 'datetime',
    ];

    // SCHEMA-CONTRATO-CARTEIRA.md §3 / INV-056 — coerência origem/
    // forma_pagamento_id: automático sempre aponta pro FormaPagamento que
    // o originou; manual nunca aponta pra nenhum (não existe fato interno
    // por trás de um Lançamento externo).
    protected static function booted(): void
    {
        $regra = function (self $lancamento) {
            if (! in_array($lancamento->origem, ['manual', 'automatico'], true)) {
                throw new LogicException("Lancamento.origem precisa ser 'manual' ou 'automatico'.");
            }
            if (! in_array($lancamento->tipo, ['entrada', 'saida'], true)) {
                throw new LogicException("Lancamento.tipo precisa ser 'entrada' ou 'saida'.");
            }

            $temFormaPagamento = $lancamento->forma_pagamento_id !== null;
            if ($lancamento->origem === 'automatico' && ! $temFormaPagamento) {
                throw new LogicException("Lancamento.origem='automatico' precisa de forma_pagamento_id preenchido.");
            }
            if ($lancamento->origem === 'manual' && $temFormaPagamento) {
                throw new LogicException("Lancamento.origem='manual' nunca pode ter forma_pagamento_id preenchido.");
            }
        };

        static::creating($regra);
        static::updating($regra);
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(Conta::class);
    }

    public function formaPagamento(): BelongsTo
    {
        return $this->belongsTo(FormaPagamento::class);
    }
}
