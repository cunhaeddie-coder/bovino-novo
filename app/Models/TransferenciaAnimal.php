<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferenciaAnimal extends Model
{
    protected $table = 'transferencia_animal';

    protected $fillable = ['transferencia_id', 'animal_origem_id', 'animal_destino_id'];

    public function transferencia(): BelongsTo
    {
        return $this->belongsTo(TransferenciaFazenda::class, 'transferencia_id');
    }

    public function animalOrigem(): BelongsTo
    {
        return $this->belongsTo(Animal::class, 'animal_origem_id');
    }

    public function animalDestino(): BelongsTo
    {
        return $this->belongsTo(Animal::class, 'animal_destino_id');
    }
}
