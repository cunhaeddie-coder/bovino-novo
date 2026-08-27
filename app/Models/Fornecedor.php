<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fornecedor extends Model
{
    protected $table = 'fornecedores';

    protected $fillable = ['nome'];

    public function compras(): HasMany
    {
        return $this->hasMany(Compra::class);
    }
}
