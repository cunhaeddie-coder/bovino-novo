<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Venda extends Model
{
    protected $fillable = [
        'fazenda_id', 'venda_original_id', 'chave_idempotencia', 'animal_ids',
        'valor_bruto', 'cpv', 'deducao_fiscal', 'fiscal_e_premissa', 'receita_liquida',
    ];

    protected $casts = [
        'animal_ids' => 'array',
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
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'venda_original_id');
    }
}
