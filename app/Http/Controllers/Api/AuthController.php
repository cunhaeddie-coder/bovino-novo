<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\Request;

/**
 * Thin wrapper — nenhuma regra de domínio aqui (SCHEMA-CONTRATO-
 * AUTENTICACAO.md §5). Toda decisão real mora em AuthService.
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function registrar(Request $request)
    {
        $dados = $request->validate([
            'nome' => ['required', 'string'],
            'email' => ['nullable', 'string'],
            'celular' => ['nullable', 'string'],
            'senha' => ['required', 'string'],
        ]);

        $usuario = $this->auth->registrar($dados['nome'], $dados['email'] ?? null, $dados['celular'] ?? null, $dados['senha']);

        return response()->json(['usuario' => $usuario], 201);
    }

    public function login(Request $request)
    {
        $dados = $request->validate([
            'identificador' => ['required', 'string'],
            'senha' => ['required', 'string'],
        ]);

        $resultado = $this->auth->login($dados['identificador'], $dados['senha']);

        return response()->json([
            'usuario' => $resultado['usuario']->load('papeis.fazenda'),
            'token' => $resultado['token'],
        ]);
    }

    public function logout(Request $request)
    {
        $this->auth->logout($request->user());

        return response()->json(['sucesso' => true]);
    }

    public function eu(Request $request)
    {
        return response()->json(['usuario' => $request->user()->load('papeis.fazenda')]);
    }
}
