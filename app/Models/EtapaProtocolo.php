<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EtapaProtocolo extends Model
{
    protected $table = 'etapas_protocolo';

    protected $fillable = ['fazenda_id', 'protocolo_reprodutivo_id', 'tipo', 'data_prevista', 'data_realizada'];

    protected $casts = [
        'data_prevista' => 'datetime',
        'data_realizada' => 'datetime',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-PROTOCOLO-REPRODUTIVO.md §2/§7 — tipo é
        // vocabulário fechado (as 3 únicas etapas do IATF); etapa com
        // data_realizada já preenchida é terminal, mesmo padrão de
        // "terminal a partir de concluída" já usado em GTA/SeparacaoVenda.
        static::creating(function (self $etapa) {
            if (! in_array($etapa->tipo, ['implante', 'prostaglandina', 'retirada_ia'], true)) {
                throw new LogicException("EtapaProtocolo.tipo precisa ser 'implante', 'prostaglandina' ou 'retirada_ia' (recebido: {$etapa->tipo}).");
            }
        });

        static::updating(function (self $etapa) {
            if ($etapa->getOriginal('data_realizada') !== null) {
                throw new LogicException(
                    'EtapaProtocolo já cumprida é terminal — nenhuma alteração é permitida.'
                );
            }
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function protocoloReprodutivo(): BelongsTo
    {
        return $this->belongsTo(ProtocoloReprodutivo::class);
    }
}
