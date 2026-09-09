<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TrocaPiquete extends Model
{
    protected $table = 'trocas_piquete';

    protected $fillable = ['fazenda_id', 'lote_id', 'piquete_origem_id', 'piquete_destino_id', 'data_troca', 'descanso_interrompido', 'chave_idempotencia'];

    protected $casts = [
        'data_troca' => 'datetime',
        'descanso_interrompido' => 'boolean',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-ROTACAO-PASTAGEM.md §7 — mesma disciplina padrão
        // de Morte/Nascimento/Evento de Saúde/Produção Leiteira (INV-026):
        // imutável desde a criação.
        static::updating(function () {
            throw new LogicException(
                'TrocaPiquete é imutável depois de registrada (mesma disciplina de INV-026) — correção deve criar um novo registro, nunca alterar este.'
            );
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function piqueteOrigem(): BelongsTo
    {
        return $this->belongsTo(Piquete::class, 'piquete_origem_id');
    }

    public function piqueteDestino(): BelongsTo
    {
        return $this->belongsTo(Piquete::class, 'piquete_destino_id');
    }
}
