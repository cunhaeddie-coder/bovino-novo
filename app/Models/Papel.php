<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Papel extends Model
{
    protected $table = 'papeis';

    protected $fillable = ['usuario_id', 'fazenda_id', 'papel'];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }
}
