<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class FolhaPagamento extends Model
{
    protected $table = 'folhas_pagamento';

    protected $fillable = ['fazenda_id', 'funcionario_id', 'mes_referencia', 'valor', 'data_geracao'];

    protected $casts = [
        'valor' => 'decimal:2',
        'data_geracao' => 'datetime',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-FOLHA-PAGAMENTO.md §7 — mesma disciplina padrão de
        // Venda/Morte/Nascimento/Consumo de Insumo (INV-026): imutável desde
        // a criação.
        static::updating(function () {
            throw new LogicException(
                'FolhaPagamento é imutável depois de gerada (mesma disciplina de INV-026) — se o salário mudou, a próxima folha já reflete o valor novo.'
            );
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function funcionario(): BelongsTo
    {
        return $this->belongsTo(Funcionario::class);
    }

    // SCHEMA-CONTRATO-FOLHA-PAGAMENTO.md §1 — sem obrigacao_financeira_id
    // aqui (evitaria referência circular); ObrigacaoFinanceira.folha_pagamento_id
    // aponta pra cá, nunca o inverso — mesmo sentido de Gta.venda_id.
    public function obrigacaoFinanceira(): HasOne
    {
        return $this->hasOne(ObrigacaoFinanceira::class);
    }
}
