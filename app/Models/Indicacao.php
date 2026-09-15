<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Indicacao extends Model
{
    protected $table = 'indicacoes';

    protected $fillable = ['parceiro_id', 'cliente_nome', 'cliente_documento', 'data_indicacao', 'chave_idempotencia', 'confirmada_em'];

    protected $casts = [
        'data_indicacao' => 'datetime',
        'confirmada_em' => 'datetime',
    ];

    // SCHEMA-CONTRATO-PARCEIROS-COMISSAO.md §3 / INV-058 — confirmada_em é
    // terminal: uma vez confirmada, nenhum caminho de código altera esse
    // campo de novo (diferente de FormaPagamento.pago_em, que Venda pode
    // reabrir via corrigir() — Indicação não tem mecanismo de correção
    // nesta rodada, então o guard aqui pode ser real).
    protected static function booted(): void
    {
        static::updating(function (self $indicacao) {
            if ($indicacao->getOriginal('confirmada_em') !== null && $indicacao->isDirty('confirmada_em')) {
                throw new LogicException('Indicacao já confirmada é terminal — confirmada_em nunca pode ser alterado de novo.');
            }
        });
    }

    public function parceiro(): BelongsTo
    {
        return $this->belongsTo(Parceiro::class);
    }

    public function comissoes(): HasMany
    {
        return $this->hasMany(Comissao::class);
    }
}
