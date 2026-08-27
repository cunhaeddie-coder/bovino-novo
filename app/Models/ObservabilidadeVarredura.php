<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

// OBSERVABILIDADE-MINIMA-VENDA.md §2 — sempre uma linha só, atualizada a
// cada execução da varredura (sucesso ou não). Nunca cresce.
class ObservabilidadeVarredura extends Model
{
    protected $table = 'observabilidade_varredura';

    protected $fillable = ['ultima_execucao_em'];

    protected $casts = [
        'ultima_execucao_em' => 'datetime',
    ];

    public static function registrarExecucao(): void
    {
        static::query()->update(['ultima_execucao_em' => now()]);
    }

    public static function ultimaExecucao(): ?Carbon
    {
        return static::query()->first()?->ultima_execucao_em;
    }
}
