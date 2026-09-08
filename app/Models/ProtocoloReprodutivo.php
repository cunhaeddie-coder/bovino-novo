<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ProtocoloReprodutivo extends Model
{
    protected $table = 'protocolos_reprodutivos';

    protected $fillable = ['fazenda_id', 'animal_ids', 'data_inicio', 'status', 'chave_idempotencia'];

    protected $casts = [
        'animal_ids' => 'array',
        'data_inicio' => 'datetime',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-PROTOCOLO-REPRODUTIVO.md §7 — terminal a partir
        // de status=concluido, mesmo padrão de Gta/SeparacaoVenda: a
        // transição em_andamento->concluido continua permitida (é assim
        // que ProtocoloReprodutivoService::cumprirEtapa() funciona), só o
        // estado terminal é protegido.
        static::updating(function (self $protocolo) {
            if ($protocolo->getOriginal('status') === 'concluido') {
                throw new LogicException(
                    'ProtocoloReprodutivo com status=concluido é terminal — nenhuma alteração é permitida.'
                );
            }
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function etapas(): HasMany
    {
        return $this->hasMany(EtapaProtocolo::class);
    }
}
