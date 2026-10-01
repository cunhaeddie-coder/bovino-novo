<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Motorista;

/**
 * Leitura de apoio pra tela de Frete/Logística (seleção de motorista pra
 * contratação direta) — só motoristas aprovados, sempre visíveis, nunca
 * isolados por Fazenda (mesmo padrão de Fornecedor: ator externo, não
 * dado da Fazenda).
 */
class MotoristaController extends Controller
{
    public function index()
    {
        $motoristas = Motorista::where('status', 'aprovado')->with('usuario')->get();

        return response()->json(['motoristas' => $motoristas]);
    }
}
