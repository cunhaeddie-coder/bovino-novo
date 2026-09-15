<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Animal;
use App\Models\Usuario;
use DomainException;
use Illuminate\Http\Request;

/**
 * Leitura de apoio pras telas de Venda/Forma de Pagamento (seleção de
 * animal) — nenhuma escrita aqui, isolamento por Fazenda igual a todo
 * Service de domínio (INV-029).
 */
class AnimalController extends Controller
{
    public function index(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'status' => ['nullable', 'string'],
        ]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $query = Animal::where('fazenda_id', $dados['fazenda_id']);
        if (! empty($dados['status'])) {
            $query->where('status', $dados['status']);
        }

        return response()->json(['animais' => $query->orderBy('id')->get()]);
    }
}
