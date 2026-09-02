<?php

namespace App\Models;

use App\Models\Builders\CompraInsumoBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class CompraInsumo extends Model
{
    protected $table = 'compras_insumo';

    protected $fillable = [
        'fazenda_id', 'fornecedor_id', 'compra_original_id', 'chave_idempotencia',
        'data_compra', 'valor_total', 'deducao_fiscal', 'fiscal_e_premissa',
    ];

    protected $casts = [
        'data_compra' => 'date',
        'valor_total' => 'decimal:2',
        'deducao_fiscal' => 'decimal:2',
        'fiscal_e_premissa' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Mesmo padrão de Compra::booted()/Venda::booted() — imutabilidade
        // depois de criada.
        static::updating(function () {
            throw new LogicException(
                'Compra de Insumo é imutável depois de criada (mesmo padrão de INV-026) — correção deve criar uma nova linha, nunca alterar esta.'
            );
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(CompraInsumo::class, 'compra_original_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(CompraInsumoItem::class);
    }

    public function obrigacaoFinanceira(): HasOne
    {
        return $this->hasOne(ObrigacaoFinanceira::class);
    }

    public function newEloquentBuilder($query): Builder
    {
        return new CompraInsumoBuilder($query);
    }
}
