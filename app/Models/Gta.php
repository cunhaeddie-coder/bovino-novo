<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Gta extends Model
{
    protected $table = 'gtas';

    protected $fillable = [
        'fazenda_id', 'animal_ids', 'destino', 'quantidade_declarada', 'valor_bruto',
        'status', 'data_emissao', 'data_conclusao', 'venda_id', 'chave_idempotencia',
    ];

    protected $casts = [
        'animal_ids' => 'array',
        'valor_bruto' => 'decimal:2',
        'data_emissao' => 'datetime',
        'data_conclusao' => 'datetime',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-GTA.md §3 — INV-036: quantidade_declarada sempre
        // bate com os animal_ids de fato vinculados, verificado na criação.
        $regraQuantidade = function (self $gta) {
            if ($gta->quantidade_declarada !== count($gta->animal_ids ?? [])) {
                throw new LogicException(
                    'Gta.quantidade_declarada precisa bater exatamente com a quantidade de animal_ids vinculados (INV-036).'
                );
            }
        };

        // SCHEMA-CONTRATO-GTA.md §2/§5 — INV-037: status=concluida exige
        // venda_id preenchido.
        $regraConclusao = function (self $gta) {
            if ($gta->status === 'concluida' && $gta->venda_id === null) {
                throw new LogicException(
                    'Gta só pode ter status=concluida quando venda_id estiver preenchido (INV-037).'
                );
            }
        };

        // SCHEMA-CONTRATO-GTA.md §7 — terminal a partir de status=concluida,
        // não desde a criação (diferente de ConsumoInsumo — GTA tem
        // lifecycle emitida->concluida, a própria conclusão precisa poder
        // gravar nesta linha).
        $regraTerminal = function (self $gta) {
            if ($gta->getOriginal('status') === 'concluida') {
                throw new LogicException(
                    'Gta com status=concluida é terminal — nenhuma alteração é permitida.'
                );
            }
        };

        static::creating($regraQuantidade);
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
