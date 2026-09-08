<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Arrendamento extends Model
{
    protected $table = 'arrendamentos';

    protected $fillable = ['fazenda_id', 'fornecedor_id', 'valor_total', 'periodicidade', 'data_inicio', 'data_fim', 'chave_idempotencia'];

    protected $casts = [
        'valor_total' => 'decimal:2',
        'data_inicio' => 'datetime',
        'data_fim' => 'datetime',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-ARRENDAMENTO.md §7 — mesma disciplina padrão dos
        // 11 verticais anteriores (INV-026): imutável desde a criação. O
        // "muda a cada parcela gerada" de MAPA-DOMINIO.md é observável via
        // ParcelaArrendamento, nunca uma edição deste registro.
        static::updating(function () {
            throw new LogicException(
                'Arrendamento é imutável depois de registrado (mesma disciplina de INV-026) — correção deve criar um novo registro, nunca alterar este.'
            );
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function parcelas(): HasMany
    {
        return $this->hasMany(ParcelaArrendamento::class);
    }
}
