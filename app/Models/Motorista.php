<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Motorista extends Model
{
    protected $fillable = ['usuario_id', 'fazenda_id', 'documento', 'status', 'aprovado_em', 'aprovado_por'];

    protected $casts = [
        'aprovado_em' => 'datetime',
    ];

    // SCHEMA-CONTRATO-FRETE-LOGISTICA.md §3 - sem guard de terminalidade
    // sobre status: um administrador pode reavaliar um Motorista reprovado
    // depois (VERTICAL-FRETE-LOGISTICA.md §9, nada foi decidido restringindo
    // isso).
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function aprovadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'aprovado_por');
    }
}
