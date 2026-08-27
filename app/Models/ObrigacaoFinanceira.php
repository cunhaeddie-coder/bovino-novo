<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObrigacaoFinanceira extends Model
{
    protected $table = 'obrigacoes_financeiras';

    protected $fillable = ['fazenda_id', 'compra_id', 'valor', 'status'];

    protected $casts = [
        'valor' => 'decimal:2',
    ];

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }
}
