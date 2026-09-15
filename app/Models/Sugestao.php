<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Sugestao extends Model
{
    protected $table = 'sugestoes';

    protected $fillable = ['fazenda_id', 'usuario_id', 'mensagem', 'resposta', 'respondida_em', 'chave_idempotencia'];

    protected $casts = [
        'respondida_em' => 'datetime',
    ];

    // SCHEMA-CONTRATO-SUPORTE-SUGESTOES-ADMIN.md §3 / INV-065 — resposta é
    // terminal: sem mecanismo de correção nesta rodada, então o guard aqui
    // pode ser real (mesmo idioma de Indicacao.confirmada_em, Vertical 28).
    protected static function booted(): void
    {
        static::updating(function (self $sugestao) {
            if ($sugestao->getOriginal('resposta') !== null && $sugestao->isDirty('resposta')) {
                throw new LogicException('Sugestao já respondida é terminal — resposta nunca pode ser alterada de novo.');
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
