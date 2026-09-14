<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfiguracaoPlataforma extends Model
{
    protected $table = 'configuracoes_plataforma';

    protected $fillable = ['chave', 'valor', 'atualizado_por'];

    public function atualizadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'atualizado_por');
    }
}
