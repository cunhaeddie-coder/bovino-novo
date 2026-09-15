<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Notificacao extends Model
{
    protected $table = 'notificacoes';

    protected $fillable = ['usuario_id', 'para_administrador', 'tipo', 'mensagem', 'sugestao_id', 'conversa_suporte_id', 'lida_em'];

    protected $casts = [
        'para_administrador' => 'boolean',
        'lida_em' => 'datetime',
    ];

    // SCHEMA-CONTRATO-SUPORTE-SUGESTOES-ADMIN.md §3 — INV-066:
    // para_administrador/usuario_id mutuamente coerentes; sugestao_id/
    // conversa_suporte_id mutuamente exclusivos (mesmo padrão de
    // ObrigacaoFinanceira::booted()).
    protected static function booted(): void
    {
        $regra = function (self $notificacao) {
            if ($notificacao->para_administrador && $notificacao->usuario_id !== null) {
                throw new LogicException('Notificacao para_administrador=true nunca pode ter usuario_id preenchido.');
            }
            if (! $notificacao->para_administrador && $notificacao->usuario_id === null) {
                throw new LogicException('Notificacao para_administrador=false precisa de usuario_id preenchido.');
            }

            $temSugestao = $notificacao->sugestao_id !== null;
            $temConversaSuporte = $notificacao->conversa_suporte_id !== null;
            if ($temSugestao === $temConversaSuporte) {
                throw new LogicException('Notificacao precisa ter exatamente um entre sugestao_id e conversa_suporte_id — nunca os dois, nunca nenhum.');
            }
        };

        static::creating($regra);
        static::updating($regra);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function sugestao(): BelongsTo
    {
        return $this->belongsTo(Sugestao::class);
    }

    public function conversaSuporte(): BelongsTo
    {
        return $this->belongsTo(ConversaSuporte::class);
    }
}
