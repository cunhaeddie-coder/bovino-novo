<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Limite de tentativas no login (Inversão, 26/10/2026 — antes de subir
        // a homologação): 5 por minuto pra cada identificador vindo do mesmo
        // IP. A chave usa o identificador como chegou, normalizado, nunca se
        // ele existe — a resposta 429 não pode revelar cadastro
        // (SCHEMA-CONTRATO-AUTENTICACAO.md §4). Corpo no mesmo formato
        // {mensagem} que o frontend já mostra.
        RateLimiter::for('login', function (Request $request) {
            $identificador = mb_strtolower(trim((string) $request->input('identificador')));

            return Limit::perMinute(5)
                ->by($identificador.'|'.$request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'mensagem' => 'Muitas tentativas de login. Tente de novo em '.$headers['Retry-After'].' segundos.',
                ], 429, $headers));
        });
    }
}
