<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComissaoPlataforma extends Model
{
    protected $table = 'comissoes_plataforma';

    protected $fillable = ['ordem_frete_id', 'valor_comissao', 'percentual_aplicado'];

    protected $casts = [
        'valor_comissao' => 'decimal:2',
        'percentual_aplicado' => 'decimal:2',
    ];

    // INV-045 - dado exclusivo de Administrador, nunca acessivel por
    // consulta escopada a Fazenda (mesmo quando a Fazenda em questao e a
    // que gerou a OrdemFrete correspondente). Sem metodo de conveniencia
    // "por Fazenda" de proposito - qualquer leitura precisa passar pela
    // checagem de Usuario.eh_administrador no Service, nao aqui.
    public function ordemFrete(): BelongsTo
    {
        return $this->belongsTo(OrdemFrete::class);
    }
}
