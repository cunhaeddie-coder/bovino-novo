<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class OrdemFrete extends Model
{
    protected $table = 'ordens_frete';

    protected $fillable = [
        'fazenda_id', 'status', 'motorista_id', 'valor_frete', 'chave_idempotencia',
        'aceita_em', 'concluida_em', 'cancelada_em',
    ];

    protected $casts = [
        'valor_frete' => 'decimal:2',
        'aceita_em' => 'datetime',
        'concluida_em' => 'datetime',
        'cancelada_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-FRETE-LOGISTICA.md §3 - status in (aceita,
        // concluida) exige motorista_id E valor_frete preenchidos, mesma
        // forma de INV-034 (Negociacao), sem numero de invariante proprio
        // (mesma classe de guard estrutural de ObrigacaoFinanceira/Animal).
        $regraConsistencia = function (self $ordem) {
            if (in_array($ordem->status, ['aceita', 'concluida'], true)
                && ($ordem->motorista_id === null || $ordem->valor_frete === null)) {
                throw new LogicException(
                    'OrdemFrete só pode ter status=aceita/concluida quando motorista_id E valor_frete estiverem preenchidos.'
                );
            }
        };

        // INV-044 - terminal a partir de concluida/cancelada, mesmo padrao
        // de Negociacao::booted().
        $regraTerminal = function (self $ordem) {
            $statusOriginal = $ordem->getOriginal('status');
            if (in_array($statusOriginal, ['concluida', 'cancelada'], true)) {
                throw new LogicException(
                    "OrdemFrete com status original '{$statusOriginal}' é terminal — nenhuma alteração é permitida."
                );
            }
        };

        static::creating($regraConsistencia);
        static::updating($regraConsistencia);
        static::updating($regraTerminal);
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function motorista(): BelongsTo
    {
        return $this->belongsTo(Motorista::class);
    }

    public function propostas(): HasMany
    {
        return $this->hasMany(PropostaFrete::class);
    }

    public function comissaoPlataforma(): HasOne
    {
        return $this->hasOne(ComissaoPlataforma::class);
    }
}
