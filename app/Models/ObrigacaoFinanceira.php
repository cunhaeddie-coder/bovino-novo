<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ObrigacaoFinanceira extends Model
{
    protected $table = 'obrigacoes_financeiras';

    // status NÃO entra aqui (INV-032) — nunca gravado, sempre computado a
    // partir de formasPagamento(). SCHEMA-CONTRATO-FORMA-PAGAMENTO.md §3,
    // Opção A: venda_id é o terceiro membro do grupo mutuamente exclusivo
    // com compra_id/compra_insumo_id; direcao (a_pagar/a_receber) sempre
    // correlacionada com qual FK está preenchida.
    // SCHEMA-CONTRATO-FOLHA-PAGAMENTO.md §4 — folha_pagamento_id é o 4º
    // membro do mesmo grupo, sempre com direcao=a_pagar.
    protected $fillable = ['fazenda_id', 'compra_id', 'compra_insumo_id', 'venda_id', 'folha_pagamento_id', 'direcao', 'valor'];

    protected $casts = [
        'valor' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-FORMA-PAGAMENTO.md §3 — compra_id, compra_insumo_id
        // e venda_id são mutuamente exclusivos (exatamente um), mesmo padrão
        // de Animal::booted() (lote_id/custo_aquisicao). direcao precisa
        // corresponder ao lado certo: a_pagar só com compra_id/compra_insumo_id,
        // a_receber só com venda_id. Guard de aplicação, não CHECK de banco
        // (Princípio 4b).
        $regra = function (self $obrigacao) {
            $deCompra = $obrigacao->compra_id !== null;
            $deCompraInsumo = $obrigacao->compra_insumo_id !== null;
            $deVenda = $obrigacao->venda_id !== null;
            $deFolhaPagamento = $obrigacao->folha_pagamento_id !== null;
            if (($deCompra ? 1 : 0) + ($deCompraInsumo ? 1 : 0) + ($deVenda ? 1 : 0) + ($deFolhaPagamento ? 1 : 0) !== 1) {
                throw new LogicException(
                    'ObrigacaoFinanceira precisa ter exatamente um entre compra_id, compra_insumo_id, venda_id e folha_pagamento_id — nunca mais de um, nunca nenhum.'
                );
            }

            $direcaoEsperada = $deVenda ? 'a_receber' : 'a_pagar';
            if ($obrigacao->direcao !== $direcaoEsperada) {
                throw new LogicException(
                    "ObrigacaoFinanceira de venda_id precisa ter direcao='a_receber'; de compra_id/compra_insumo_id/folha_pagamento_id precisa ter direcao='a_pagar'."
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

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    public function compraInsumo(): BelongsTo
    {
        return $this->belongsTo(CompraInsumo::class);
    }

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class);
    }

    public function folhaPagamento(): BelongsTo
    {
        return $this->belongsTo(FolhaPagamento::class);
    }

    public function formasPagamento(): HasMany
    {
        return $this->hasMany(FormaPagamento::class);
    }

    // INV-032 — status é sempre derivado das Formas de Pagamento reais,
    // nunca uma coluna gravada. Nenhuma → 'pendente'; todas com pago_em →
    // 'pago'; mistura → 'parcial'. Sem Forma de Pagamento nenhuma (nunca
    // deveria acontecer em dado criado por um Service — todo Service cria
    // ao menos uma) → 'pendente', nunca presume pago por ausência de dado.
    protected function status(): Attribute
    {
        return Attribute::get(function () {
            $formas = $this->relationLoaded('formasPagamento')
                ? $this->formasPagamento
                : $this->formasPagamento()->get();

            if ($formas->isEmpty()) {
                return 'pendente';
            }

            $pagas = $formas->filter(fn (FormaPagamento $f) => $f->pago_em !== null)->count();

            return match (true) {
                $pagas === $formas->count() => 'pago',
                $pagas === 0 => 'pendente',
                default => 'parcial',
            };
        });
    }
}
