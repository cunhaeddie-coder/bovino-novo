<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LancamentoFiscal extends Model
{
    protected $table = 'lancamentos_fiscais';

    protected $fillable = ['venda_id', 'deducao_fiscal', 'taxa_aplicada', 'fiscal_e_premissa', 'data_lancamento'];

    protected $casts = [
        'deducao_fiscal' => 'decimal:2',
        'taxa_aplicada' => 'decimal:4',
        'fiscal_e_premissa' => 'boolean',
        'data_lancamento' => 'datetime',
    ];

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class);
    }
}
