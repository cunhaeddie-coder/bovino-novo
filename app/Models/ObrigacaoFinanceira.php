<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ObrigacaoFinanceira extends Model
{
    protected $table = 'obrigacoes_financeiras';

    protected $fillable = ['fazenda_id', 'compra_id', 'compra_insumo_id', 'valor', 'status'];

    protected $casts = [
        'valor' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-COMPRA-INSUMO.md §7 — compra_id e compra_insumo_id
        // são mutuamente exclusivos, mesmo padrão de
        // Animal::booted() (lote_id/custo_aquisicao). Guard de aplicação,
        // não CHECK de banco (Princípio 4b).
        $regra = function (self $obrigacao) {
            $deCompra = $obrigacao->compra_id !== null;
            $deCompraInsumo = $obrigacao->compra_insumo_id !== null;
            if ($deCompra === $deCompraInsumo) {
                throw new LogicException(
                    'ObrigacaoFinanceira precisa ter exatamente um entre compra_id e compra_insumo_id — nunca os dois, nunca nenhum.'
                );
            }
        };
        static::creating($regra);
        static::updating($regra);
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    public function compraInsumo(): BelongsTo
    {
        return $this->belongsTo(CompraInsumo::class);
    }
}
