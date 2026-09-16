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
            'categoria' => ['nullable', 'string'],
            'finalidade' => ['nullable', 'string'],
            'raca' => ['nullable', 'string'],
            'tipo_origem' => ['nullable', 'string'],
            'lote_id' => ['nullable', 'integer'],
        ]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $query = Animal::where('fazenda_id', $dados['fazenda_id']);
        if (! empty($dados['status'])) {
            $query->where('status', $dados['status']);
        }
        if (! empty($dados['categoria'])) {
            $query->where('categoria', 'like', '%'.$dados['categoria'].'%');
        }
        if (! empty($dados['finalidade'])) {
            $query->where('finalidade', 'like', '%'.$dados['finalidade'].'%');
        }
        if (! empty($dados['raca'])) {
            $query->where('raca', 'like', '%'.$dados['raca'].'%');
        }
        if (! empty($dados['tipo_origem'])) {
            $query->where('tipo_origem', $dados['tipo_origem']);
        }
        if (array_key_exists('lote_id', $dados) && $dados['lote_id'] !== null) {
            $query->where('lote_id', $dados['lote_id']);
        }

        return response()->json(['animais' => $query->orderBy('id')->get()]);
    }
}
