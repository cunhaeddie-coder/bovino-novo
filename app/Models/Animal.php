<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Animal extends Model
{
    protected $table = 'animais';

    protected $fillable = ['fazenda_id', 'lote_id', 'custo_aquisicao', 'status', 'data_saida'];

    protected $casts = [
        'data_saida' => 'date',
        'custo_aquisicao' => 'decimal:2',
    ];

    // SCHEMA-CONTRATO-COMPRA.md §5 — lote_id e custo_aquisicao são
    // mutuamente exclusivos: nunca os dois preenchidos, nunca os dois nulos.
    // Guard de aplicação (não DB CHECK — SQLite não aceita CHECK via ALTER
    // TABLE em coluna existente, e um guard que só existisse em MySQL
    // reproduziria exatamente o ponto cego que o Princípio 4b existe pra
    // evitar). Risco residual do mesmo tipo de INV-026: DB::table('animais')
    // ->insert()/->update() cru ainda contorna isto.
    protected static function booted(): void
    {
        $regra = function (self $animal) {
            $temLote = $animal->lote_id !== null;
            $temCusto = $animal->custo_aquisicao !== null;
            if ($temLote === $temCusto) {
                throw new LogicException(
                    'Animal precisa ter exatamente um entre lote_id e custo_aquisicao — nunca os dois, nunca nenhum.'
                );
            }
        };

        static::creating($regra);
        static::updating($regra);
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function itensDeCompra(): HasMany
    {
        return $this->hasMany(CompraItem::class);
    }
}
