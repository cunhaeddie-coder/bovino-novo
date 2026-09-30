<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Piquete;
use App\Models\Usuario;
use DomainException;
use Illuminate\Http\Request;

/**
 * Leitura de apoio pra tela de Pastagem/Curral (seleção de piquete
 * origem/destino) — nenhuma escrita aqui, isolamento por Fazenda.
 */
class PiqueteController extends Controller
{
    public function index(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['required', 'integer']]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $piquetes = Piquete::where('fazenda_id', $dados['fazenda_id'])->orderBy('nome')->get();

        return response()->json(['piquetes' => $piquetes]);
    }
}
