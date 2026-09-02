<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FormaPagamento extends Model
{
    protected $table = 'formas_pagamento';

    protected $fillable = [
        'obrigacao_financeira_id', 'nome', 'unidade', 'valor', 'data', 'vencimento',
        'pago_em', 'meio_liquidacao', 'cotacao_arroba_na_liquidacao', 'valor_liquidado_reais', 'animal_id',
    ];

    protected $casts = [
        'valor' => 'decimal:2',
        'data' => 'date',
        'vencimento' => 'date',
        'pago_em' => 'date',
        'cotacao_arroba_na_liquidacao' => 'decimal:2',
        'valor_liquidado_reais' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-FORMA-PAGAMENTO.md §4/§5 — guards de aplicação,
        // não CHECK de banco (Princípio 4b).
        $regra = function (self $forma) {
            if (! in_array($forma->unidade, ['dinheiro', 'arroba'], true)) {
                throw new LogicException("FormaPagamento.unidade precisa ser 'dinheiro' ou 'arroba', nunca outro valor.");
            }

            if ($forma->meio_liquidacao !== null && ! in_array($forma->meio_liquidacao, ['dinheiro', 'especie'], true)) {
                throw new LogicException("FormaPagamento.meio_liquidacao precisa ser null, 'dinheiro' ou 'especie'.");
            }

            // §5 — liquidação em espécie sempre referencia o item entregue,
            // mas só depois de liquidada: quando é a Fazenda quem RECEBE
            // (direcao=a_receber), o Animal só existe a partir da própria
            // liquidação (FormaPagamentoService::liquidar()) — exigir
            // animal_id antes disso tornaria essa direção impossível de
            // declarar. meio_liquidacao=especie já pago sem animal_id
            // continua sempre inválido.
            if ($forma->meio_liquidacao === 'especie' && $forma->pago_em !== null && $forma->animal_id === null) {
                throw new LogicException('FormaPagamento paga com meio_liquidacao=especie precisa de animal_id.');
            }

            // §2 — cotação/valor liquidado só existem depois de liquidada,
            // nunca antes (arroba não tem valor em R$ até a conversão real).
            if ($forma->pago_em === null && ($forma->cotacao_arroba_na_liquidacao !== null || $forma->valor_liquidado_reais !== null)) {
                throw new LogicException('cotacao_arroba_na_liquidacao/valor_liquidado_reais só podem existir depois de pago_em preenchido.');
            }

            // §4 — arroba liquidada em dinheiro precisa da cotação pra
            // derivar valor_liquidado_reais; nunca digitado direto (§2).
            if ($forma->unidade === 'arroba' && $forma->pago_em !== null && $forma->cotacao_arroba_na_liquidacao === null) {
                throw new LogicException('FormaPagamento em arroba liquidada precisa de cotacao_arroba_na_liquidacao.');
            }
        };
        static::creating($regra);
        static::updating($regra);
    }

    public function obrigacaoFinanceira(): BelongsTo
    {
        return $this->belongsTo(ObrigacaoFinanceira::class);
    }

    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }
}
