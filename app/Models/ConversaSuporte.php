<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ConversaSuporte extends Model
{
    protected $table = 'conversas_suporte';

    protected $fillable = ['fazenda_id', 'usuario_id', 'mensagem', 'resposta', 'respondida_em', 'chave_idempotencia'];

    protected $casts = [
        'respondida_em' => 'datetime',
    ];

    // SCHEMA-CONTRATO-SUPORTE-SUGESTOES-ADMIN.md §3 / INV-065 — mesmo guard
    // de Sugestao.
    protected static function booted(): void
    {
        static::updating(function (self $conversa) {
            if ($conversa->getOriginal('resposta') !== null && $conversa->isDirty('resposta')) {
                throw new LogicException('ConversaSuporte já respondida é terminal — resposta nunca pode ser alterada de novo.');
            }
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
