<?php

namespace App\Models;

use App\Models\Builders\VendaBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class Venda extends Model
{
    protected $fillable = [
        'fazenda_id', 'venda_original_id', 'chave_idempotencia', 'animal_ids', 'data_venda',
        'valor_bruto', 'cpv', 'deducao_fiscal', 'fiscal_e_premissa', 'receita_liquida',
    ];

    protected $casts = [
        'animal_ids' => 'array',
        'data_venda' => 'datetime',
        'valor_bruto' => 'decimal:2',
        'cpv' => 'decimal:2',
        'deducao_fiscal' => 'decimal:2',
        'fiscal_e_premissa' => 'boolean',
        'receita_liquida' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // INV-026 / SCHEMA-CONTRATO-VENDA.md §5 — nenhuma venda tem caminho de
        // UPDATE no domínio. Correção é sempre uma NOVA linha (venda_original_id).
        static::updating(function () {
            throw new LogicException(
                'Venda é imutável depois de criada (INV-026) — correção deve criar uma nova linha, nunca alterar esta.'
            );
        });

        // GATE-DECISAO-DOMINIO-DATA-HORA.md — decisão direta do produtor
        // (04/09/2026): venda precisa constar com data e hora, sem exceção —
        // mesma disciplina de INV-033 (vencimento de Forma de Pagamento).
        // Nullable no schema (Princípio 4b), obrigatório por guard.
        static::creating(function (self $venda) {
            if ($venda->data_venda === null) {
                throw new LogicException('Venda precisa de data_venda preenchida (data e hora reais do fato) — nunca nula.');
            }
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'venda_original_id');
    }

    public function obrigacaoFinanceira(): HasOne
    {
        return $this->hasOne(ObrigacaoFinanceira::class);
    }

    public function newEloquentBuilder($query): Builder
    {
        return new VendaBuilder($query);
    }
}
