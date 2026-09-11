<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EventoSaude extends Model
{
    protected $table = 'eventos_saude';

    protected $fillable = ['fazenda_id', 'animal_ids', 'descricao', 'certificado', 'tipo_vacina', 'consumo_insumo_id', 'data_aplicacao', 'chave_idempotencia'];

    protected $casts = [
        'animal_ids' => 'array',
        'data_aplicacao' => 'datetime',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-EVENTO-SAUDE.md §6 — mesma disciplina padrão de
        // Consumo de Insumo/Morte/Nascimento/Folha de Pagamento (INV-026):
        // imutável desde a criação.
        static::updating(function () {
            throw new LogicException(
                'EventoSaude é imutável depois de registrado (mesma disciplina de INV-026) — correção deve criar um novo registro, nunca alterar este.'
            );
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function consumoInsumo(): BelongsTo
    {
        return $this->belongsTo(ConsumoInsumo::class);
    }
}
