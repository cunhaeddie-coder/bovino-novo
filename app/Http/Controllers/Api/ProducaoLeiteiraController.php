<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProducaoLeiteira;
use App\Models\Usuario;
use App\Services\ProducaoLeiteiraService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Thin wrapper — toda decisão real mora em ProducaoLeiteiraService, já
 * provado desde o Vertical 14.
 */
class ProducaoLeiteiraController extends Controller
{
    public function __construct(private readonly ProducaoLeiteiraService $producoes) {}

    public function index(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['required', 'integer']]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $producoes = ProducaoLeiteira::where('fazenda_id', $dados['fazenda_id'])->orderByDesc('data_producao')->get();

        return response()->json(['producoes' => $producoes]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'animal_id' => ['required', 'integer'],
            'data_producao' => ['required', 'string'],
            'quantidade_total' => ['required', 'numeric'],
            'quantidade_vendida' => ['required', 'numeric'],
            'quantidade_bezerro' => ['required', 'numeric'],
            'chave_idempotencia' => ['required', 'string'],
        ]);

        $resultado = $this->producoes->registrar(
            $request->user()->id,
            $dados['fazenda_id'],
            $dados['animal_id'],
            $dados['data_producao'],
            (float) $dados['quantidade_total'],
            (float) $dados['quantidade_vendida'],
            (float) $dados['quantidade_bezerro'],
            $dados['chave_idempotencia']
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'producao' => $resultado['producao'],
        ], $resultado['reenvio_detectado'] ? 200 : 201);
    }
}
