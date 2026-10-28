<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Anuncio extends Model
{
    protected $table = 'anuncios';

    protected $fillable = ['fazenda_id', 'preco_total', 'status', 'publicado_em', 'encerrado_em'];

    // GATE-DECISAO-DOMINIO-DATA-HORA.md (04/09/2026) — extensão confirmada
    // pelo produtor: publicado_em/encerrado_em também precisam de data e hora.
    protected $casts = [
        'preco_total' => 'decimal:2',
        'publicado_em' => 'datetime',
        'encerrado_em' => 'datetime',
    ];

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    // SCHEMA-CONTRATO-MARKETPLACE.md §1 — Animais reais do Rebanho, nunca um
    // snapshot próprio (correção do achado do LAB-SA-017).
    public function animais(): BelongsToMany
    {
        return $this->belongsToMany(Animal::class, 'anuncio_animal');
    }

    public function negociacoes(): HasMany
    {
        return $this->hasMany(Negociacao::class);
    }

    // INV-023 — disponível é sempre checado contra o estado atual dos
    // animais, nunca só contra o status do próprio Anúncio: um animal que
    // saiu do rebanho por outro canal (venda direta, GTA, Separação, morte,
    // transferência) tira o Anúncio da vitrine (conferência, 27/10/2026).
    public function scopeDisponiveis(Builder $query): Builder
    {
        return $query->where('status', 'ativo')
            ->whereDoesntHave('animais', fn (Builder $animal) => $animal->where('status', '!=', 'ativo'));
    }

    // O que a Fazenda dona vê: 'indisponivel' quando o Anúncio segue 'ativo'
    // mas algum animal já saiu do rebanho (INV-023).
    protected function situacao(): Attribute
    {
        return Attribute::get(function () {
            if ($this->status !== 'ativo') {
                return $this->status;
            }

            return $this->animais->contains(fn (Animal $animal) => $animal->status !== 'ativo') ? 'indisponivel' : 'ativo';
        });
    }
}
