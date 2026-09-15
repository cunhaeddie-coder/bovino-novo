<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AcertoRescisao extends Model
{
    protected $table = 'acertos_rescisao';

    protected $fillable = ['fazenda_id', 'funcionario_id', 'chave_idempotencia'];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-ACERTO-RESCISAO.md §7 — mesma disciplina padrão de
        // TransferenciaFazenda/Gta/SeparacaoVenda (INV-026): imutável desde
        // a criação.
        static::updating(function () {
            throw new LogicException(
                'AcertoRescisao é imutável depois de registrado (mesma disciplina de INV-026) — correção deve criar um novo registro, nunca alterar este.'
            );
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function funcionario(): BelongsTo
    {
        return $this->belongsTo(Funcionario::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ItemAcertoRescisao::class);
    }
}
