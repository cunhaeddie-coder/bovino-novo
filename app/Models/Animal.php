<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Animal extends Model
{
    protected $table = 'animais';

    protected $fillable = ['fazenda_id', 'lote_id', 'custo_aquisicao', 'status', 'categoria', 'finalidade', 'data_saida', 'tipo_origem', 'mae_id', 'peso_nascimento', 'raca'];

    protected $casts = [
        'data_saida' => 'date',
        'custo_aquisicao' => 'decimal:2',
        'peso_nascimento' => 'decimal:2',
    ];

    // SCHEMA-CONTRATO-COMPRA.md §5 — lote_id e custo_aquisicao são
    // mutuamente exclusivos: nunca os dois preenchidos, nunca os dois nulos.
    // Guard de aplicação (não DB CHECK — SQLite não aceita CHECK via ALTER
    // TABLE em coluna existente, e um guard que só existisse em MySQL
    // reproduziria exatamente o ponto cego que o Princípio 4b existe pra
    // evitar). Risco residual do mesmo tipo de INV-026: DB::table('animais')
    // ->insert()/->update() cru ainda contorna isto.
    //
    // SCHEMA-CONTRATO-TRANSFERENCIA-FAZENDA.md §3 / INV-052 — diferente de
    // 'vendido'/'morto' (sem guard de model, reabertos de propósito por
    // VendaService::corrigir()), 'transferido' não tem nenhum mecanismo de
    // correção nesta rodada — por isso o guard de terminalidade aqui é real,
    // mesmo idioma já usado em Gta/SeparacaoVenda/Negociacao/OrdemFrete,
    // aplicado a Animal pela primeira vez.
    protected static function booted(): void
    {
        $regra = function (self $animal) {
            $temLote = $animal->lote_id !== null;
            $temCusto = $animal->custo_aquisicao !== null;
            if ($temLote === $temCusto) {
                throw new LogicException(
                    'Animal precisa ter exatamente um entre lote_id e custo_aquisicao — nunca os dois, nunca nenhum.'
                );
            }
        };

        static::creating($regra);
        static::updating($regra);

        static::updating(function (self $animal) {
            if ($animal->getOriginal('status') === 'transferido') {
                throw new LogicException(
                    "Animal com status original 'transferido' é terminal — nenhuma alteração é permitida."
                );
            }
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function mae(): BelongsTo
    {
        return $this->belongsTo(Animal::class, 'mae_id');
    }

    public function itensDeCompra(): HasMany
    {
        return $this->hasMany(CompraItem::class);
    }
}
