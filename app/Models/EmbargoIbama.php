<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmbargoIbama extends Model
{
    protected $table = 'embargos_ibama';

    protected $fillable = ['documento', 'situacao', 'nome', 'municipio', 'estado', 'data_embargo'];

    protected $casts = [
        'data_embargo' => 'date',
    ];
}
