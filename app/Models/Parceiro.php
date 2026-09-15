<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Parceiro extends Model
{
    protected $table = 'parceiros';

    protected $fillable = ['nome', 'crm_crc'];

    public function indicacoes(): HasMany
    {
        return $this->hasMany(Indicacao::class);
    }
}
