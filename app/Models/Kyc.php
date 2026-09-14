<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Kyc extends Model
{
    protected $fillable = ['titular_id', 'status', 'motivo_reprovacao', 'verificado_em'];

    protected $casts = [
        'verificado_em' => 'datetime',
    ];

    // SCHEMA-CONTRATO-KYC.md §3/§8 (reabertura) - sem guard de
    // terminalidade: resubmissao e esperada e valida (um documento
    // corrigido pode ser reenviado, mudando status de reprovado pra
    // aprovado ou vice-versa). Kyc e 1:1 com Titular, nao mais com Fazenda
    // (Vertical 25) - documento/tipo_documento vivem so em Titular agora.
    public function titular(): BelongsTo
    {
        return $this->belongsTo(Titular::class);
    }
}
