<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SeparacaoVenda extends Model
{
    protected $table = 'separacoes_venda';

    protected $fillable = [
        'fazenda_id', 'animal_ids', 'valor_total',
        'status', 'data_separacao', 'data_conclusao', 'venda_id', 'chave_idempotencia',
    ];

    protected $casts = [
        'animal_ids' => 'array',
        'valor_total' => 'decimal:2',
        'data_separacao' => 'datetime',
        'data_conclusao' => 'datetime',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-SEPARACAO-VENDA.md §2 — status=concluida exige
        // venda_id preenchido (mesmo formato de INV-034/INV-037).
        $regraConclusao = function (self $separacao) {
            if ($separacao->status === 'concluida' && $separacao->venda_id === null) {
                throw new LogicException(
                    'SeparacaoVenda só pode ter status=concluida quando venda_id estiver preenchido.'
                );
            }
        };

        // SCHEMA-CONTRATO-SEPARACAO-VENDA.md §7 — terminal a partir de
        // status=concluida, não desde a criação (lifecycle aberta->concluida,
        // a própria conclusão precisa poder gravar nesta linha).
        $regraTerminal = function (self $separacao) {
            if ($separacao->getOriginal('status') === 'concluida') {
                throw new LogicException(
                    'SeparacaoVenda com status=concluida é terminal — nenhuma alteração é permitida.'
                );
            }
        };

        static::creating($regraConclusao);
        static::updating($regraConclusao);
        static::updating($regraTerminal);
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class);
    }
}
