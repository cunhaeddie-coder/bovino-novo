<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ConfirmacaoPrenhez extends Model
{
    protected $table = 'confirmacoes_prenhez';

    protected $fillable = ['fazenda_id', 'vaca_id', 'resultado', 'tipo_exame', 'data_confirmacao', 'data_parto_estimada', 'chave_idempotencia'];

    protected $casts = [
        'data_confirmacao' => 'datetime',
        'data_parto_estimada' => 'date',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-MARCACAO-CIO-PRENHEZ.md §2 — resultado é
        // vocabulário fechado: exame clínico, não campo livre de produto.
        $regraResultado = function (self $confirmacao) {
            if (! in_array($confirmacao->resultado, ['positivo', 'negativo'], true)) {
                throw new LogicException(
                    "ConfirmacaoPrenhez.resultado só aceita 'positivo' ou 'negativo' (recebido: {$confirmacao->resultado})."
                );
            }

            // SCHEMA-CONTRATO-MARCACAO-CIO-PRENHEZ.md §3 — data_parto_estimada
            // só existe quando resultado=positivo; negativo nunca tem parto a
            // estimar.
            if ($confirmacao->resultado === 'negativo' && $confirmacao->data_parto_estimada !== null) {
                throw new LogicException(
                    'ConfirmacaoPrenhez com resultado=negativo não pode ter data_parto_estimada.'
                );
            }
            if ($confirmacao->resultado === 'positivo' && $confirmacao->data_parto_estimada === null) {
                throw new LogicException(
                    'ConfirmacaoPrenhez com resultado=positivo precisa ter data_parto_estimada calculada.'
                );
            }
        };

        // SCHEMA-CONTRATO-MARCACAO-CIO-PRENHEZ.md §7 — mesma disciplina
        // padrão de Morte/Nascimento/Evento de Saúde (INV-026): imutável
        // desde a criação.
        static::creating($regraResultado);
        static::updating(function () {
            throw new LogicException(
                'ConfirmacaoPrenhez é imutável depois de registrada (mesma disciplina de INV-026) — correção deve criar um novo registro, nunca alterar este.'
            );
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function vaca(): BelongsTo
    {
        return $this->belongsTo(Animal::class, 'vaca_id');
    }
}
