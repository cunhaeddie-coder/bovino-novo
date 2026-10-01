<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IntelligenciaMercadoService;
use Illuminate\Http\Request;

/**
 * Thin wrapper — toda decisão real mora em IntelligenciaMercadoService, já
 * provado desde o Vertical 21. Consulta pura, agregada sobre a plataforma
 * inteira, gateada por plano (Pennant) — nunca escreve nada.
 */
class IntelligenciaMercadoController extends Controller
{
    public function __construct(private readonly IntelligenciaMercadoService $inteligencia) {}

    public function cotacoes(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['required', 'integer']]);

        $cotacoes = $this->inteligencia->cotacoesRealizadas($request->user()->id, (int) $dados['fazenda_id']);

        return response()->json(['cotacoes' => $cotacoes]);
    }
}
