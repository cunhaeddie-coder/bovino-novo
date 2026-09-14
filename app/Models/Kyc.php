<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Kyc extends Model
{
    protected $fillable = ['fazenda_id', 'documento', 'tipo_documento', 'status', 'motivo_reprovacao', 'verificado_em'];

    protected $casts = [
        'verificado_em' => 'datetime',
    ];

    // SCHEMA-CONTRATO-KYC.md §3 - sem guard de terminalidade: resubmissao e
    // esperada e valida (um documento corrigido pode ser reenviado, mudando
    // status de reprovado pra aprovado ou vice-versa).
    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }
}
