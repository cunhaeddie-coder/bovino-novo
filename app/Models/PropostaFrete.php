<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PropostaFrete extends Model
{
    protected $table = 'propostas_frete';

    protected $fillable = ['ordem_frete_id', 'motorista_id', 'valor_proposto', 'status'];

    protected $casts = [
        'valor_proposto' => 'decimal:2',
    ];

    // SCHEMA-CONTRATO-FRETE-LOGISTICA.md §3 - terminal a partir de status
    // in (aceita, recusada), mesmo padrao de OrdemFrete/Negociacao.
    protected static function booted(): void
    {
        $regraTerminal = function (self $proposta) {
            $statusOriginal = $proposta->getOriginal('status');
            if (in_array($statusOriginal, ['aceita', 'recusada'], true)) {
                throw new LogicException(
                    "PropostaFrete com status original '{$statusOriginal}' é terminal — nenhuma alteração é permitida."
                );
            }
        };

        static::updating($regraTerminal);
    }

    public function ordemFrete(): BelongsTo
    {
        return $this->belongsTo(OrdemFrete::class);
    }

    public function motorista(): BelongsTo
    {
        return $this->belongsTo(Motorista::class);
    }
}
