<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Negociacao extends Model
{
    protected $table = 'negociacoes';

    protected $fillable = [
        'anuncio_id', 'fazenda_compradora_id', 'preco_proposto', 'status', 'chave_idempotencia',
        'confirmado_vendedor_em', 'confirmado_comprador_em', 'venda_id', 'compra_id', 'concluida_em',
    ];

    protected $casts = [
        'preco_proposto' => 'decimal:2',
        'confirmado_vendedor_em' => 'datetime',
        'confirmado_comprador_em' => 'datetime',
        'concluida_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-MARKETPLACE.md §5 — INV-034: status=concluida
        // exige venda_id E compra_id, sempre os dois — preenchidos por 2
        // confirmações independentes (NegociacaoService), nunca a mesma
        // transação.
        $regraConclusao = function (self $negociacao) {
            if ($negociacao->status === 'concluida' && ($negociacao->venda_id === null || $negociacao->compra_id === null)) {
                throw new LogicException(
                    'Negociacao só pode ter status=concluida quando venda_id E compra_id estiverem preenchidos (INV-034).'
                );
            }
        };

        // §6 — Negociação concluida/cancelada/recusada é terminal, mesma
        // disciplina de INV-026 (Venda/Compra nunca sobrescrevem um fato já
        // concluído) — diferente de Forma de Pagamento, que aceita edição
        // contínua. preco_proposto só é editável em status=proposta (§3).
        $regraTerminal = function (self $negociacao) {
            $statusOriginal = $negociacao->getOriginal('status');
            if (in_array($statusOriginal, ['concluida', 'cancelada', 'recusada'], true)) {
                throw new LogicException(
                    "Negociacao com status original '{$statusOriginal}' é terminal — nenhuma alteração é permitida."
                );
            }
        };

        static::creating($regraConclusao);
        static::updating($regraConclusao);
        static::updating($regraTerminal);
    }

    public function anuncio(): BelongsTo
    {
        return $this->belongsTo(Anuncio::class);
    }

    public function fazendaCompradora(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class, 'fazenda_compradora_id');
    }

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class);
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }
}
