<?php

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Achado real (smoke test HTTP, não capturado pelos testes de
        // feature): API pura, sem nenhuma rota "login" nomeada. O padrão do
        // framework (Authenticate::unauthenticated()) tenta redirect_to
        // route('login') quando a requisição não pede JSON explicitamente
        // (Accept header ausente) — vira RouteNotFoundException, 500 cru,
        // em vez do 401 esperado. redirectUsing(null) força sempre a
        // exceção JSON, nunca tenta redirecionar.
        Authenticate::redirectUsing(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Toda a camada de domínio (32 verticais) lança DomainException pra
        // recusa de regra de negócio — nunca existiu tradução pra resposta
        // HTTP até este vertical (Autenticação, 1ª rota HTTP real do
        // projeto). 422, não 500: é rejeição de regra, não erro do servidor.
        $exceptions->render(function (DomainException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['mensagem' => $e->getMessage()], 422);
            }
        });
    })->create();
