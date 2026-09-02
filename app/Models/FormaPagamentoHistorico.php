<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// SCHEMA-CONTRATO-FORMA-PAGAMENTO.md §6 — snapshot do estado ANTERIOR a uma
// edição de FormaPagamento. Write-only de auditoria, sem guard de domínio —
// diferente de INV-026, aqui é FormaPagamento que permanece mutável; esta
// tabela só existe pra nunca perder o que foi combinado antes.
class FormaPagamentoHistorico extends Model
{
    public $timestamps = false;

    protected $table = 'formas_pagamento_historico';

    protected $fillable = ['forma_pagamento_id', 'nome', 'valor', 'unidade', 'vencimento', 'alterado_em', 'alterado_por'];

    protected $casts = [
        'valor' => 'decimal:2',
        'vencimento' => 'date',
        'alterado_em' => 'datetime',
    ];

    public function formaPagamento(): BelongsTo
    {
        return $this->belongsTo(FormaPagamento::class);
    }

    public function alteradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'alterado_por');
    }
}
