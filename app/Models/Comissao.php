<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Comissao extends Model
{
    protected $table = 'comissoes';

    protected $fillable = ['indicacao_id', 'numero_parcela', 'valor', 'percentual_aplicado', 'valor_mensalidade_base'];

    protected $casts = [
        'valor' => 'decimal:2',
        'percentual_aplicado' => 'decimal:2',
        'valor_mensalidade_base' => 'decimal:2',
    ];

    public function indicacao(): BelongsTo
    {
        return $this->belongsTo(Indicacao::class);
    }
}
