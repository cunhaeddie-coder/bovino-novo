<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Funcionario extends Model
{
    protected $table = 'funcionarios';

    protected $fillable = [
        'fazenda_id', 'nome', 'cargo', 'salario', 'status',
        'data_contratacao', 'data_desligamento', 'chave_idempotencia',
    ];

    protected $casts = [
        'salario' => 'decimal:2',
        'data_contratacao' => 'datetime',
        'data_desligamento' => 'datetime',
    ];

    // SCHEMA-CONTRATO-FOLHA-PAGAMENTO.md §7 — entidade mutável (mesmo
    // tratamento de Fornecedor/Insumo/Lote), não um evento imutável. Único
    // guard real: salário sempre positivo.
    protected static function booted(): void
    {
        $regra = function (self $funcionario) {
            if ($funcionario->salario <= 0) {
                throw new LogicException('Funcionario.salario precisa ser positivo.');
            }
        };

        static::creating($regra);
        static::updating($regra);
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function folhasPagamento(): HasMany
    {
        return $this->hasMany(FolhaPagamento::class);
    }
}
