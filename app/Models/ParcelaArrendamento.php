<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ParcelaArrendamento extends Model
{
    protected $table = 'parcelas_arrendamento';

    protected $fillable = ['fazenda_id', 'arrendamento_id', 'numero_parcela', 'valor', 'data_geracao'];

    protected $casts = [
        'valor' => 'decimal:2',
        'data_geracao' => 'datetime',
    ];

    protected static function booted(): void
    {
        // SCHEMA-CONTRATO-ARRENDAMENTO.md §7 — mesma disciplina padrão de
        // Consumo de Insumo/Morte/Nascimento/Folha de Pagamento/Evento de
        // Saúde (INV-026): imutável desde a criação.
        static::updating(function () {
            throw new LogicException(
                'ParcelaArrendamento é imutável depois de gerada (mesma disciplina de INV-026) — correção deve criar um novo registro, nunca alterar este.'
            );
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function arrendamento(): BelongsTo
    {
        return $this->belongsTo(Arrendamento::class);
    }

    // SCHEMA-CONTRATO-ARRENDAMENTO.md §1/§5 — sem obrigacao_financeira_id
    // aqui (evitaria referência circular); ObrigacaoFinanceira.parcela_arrendamento_id
    // aponta pra cá, nunca o inverso — mesmo sentido de FolhaPagamento.obrigacaoFinanceira().
    public function obrigacaoFinanceira(): HasOne
    {
        return $this->hasOne(ObrigacaoFinanceira::class);
    }
}
