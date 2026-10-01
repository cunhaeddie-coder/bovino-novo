<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FolhaPagamentoService;
use Illuminate\Http\Request;

/**
 * Thin wrapper — toda decisão real mora em FolhaPagamentoService, já
 * provado desde o Vertical 10.
 */
class FolhaPagamentoController extends Controller
{
    public function __construct(private readonly FolhaPagamentoService $folhas) {}

    public function gerarMes(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'mes_referencia' => ['required', 'string'],
        ]);

        $resultado = $this->folhas->gerarMes($request->user()->id, $dados['fazenda_id'], $dados['mes_referencia']);

        return response()->json([
            'geradas' => $resultado['geradas'],
            'puladas' => $resultado['puladas'],
        ], 201);
    }
}
