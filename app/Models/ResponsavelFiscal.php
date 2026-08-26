<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponsavelFiscal extends Model
{
    protected $table = 'responsavel_fiscal';

    protected $primaryKey = 'fazenda_id';

    public $incrementing = false;

    protected $fillable = ['fazenda_id', 'usuario_id', 'taxa_venda_animal', 'taxa_e_premissa'];

    protected $casts = [
        'taxa_venda_animal' => 'decimal:4',
        'taxa_e_premissa' => 'boolean',
    ];

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
