<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Titular extends Model
{
    protected $table = 'titulares';

    protected $fillable = ['documento', 'tipo_documento'];

    public function fazendas(): HasMany
    {
        return $this->hasMany(Fazenda::class);
    }

    public function kyc(): HasOne
    {
        return $this->hasOne(Kyc::class);
    }
}
