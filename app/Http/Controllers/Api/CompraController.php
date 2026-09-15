<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Compra;
use App\Models\Usuario;
use App\Services\CompraService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Thin wrapper — nenhuma regra de domínio aqui. Toda decisão real mora em
 * CompraService, já provado (schema+domínio+concorrência real) desde o
 * Vertical 2.
 */
class CompraController extends Controller
{
    public function __construct(private readonly CompraService $compras) {}

    public function index(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['required', 'integer']]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $compras = Compra::where('fazenda_id', $dados['fazenda_id'])->orderByDesc('data_compra')->get();

        return response()->json(['compras' => $compras]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'fornecedor_id' => ['required', 'integer'],
            'valores_por_animal' => ['required', 'array', 'min:1'],
            'valores_por_animal.*' => ['numeric'],
            'data_compra' => ['required', 'string'],
            'chave_idempotencia' => ['required', 'string'],
        ]);

        $resultado = $this->compras->registrar(
            $request->user()->id,
            $dados['fazenda_id'],
            $dados['fornecedor_id'],
            array_map(fn ($v) => (float) $v, $dados['valores_por_animal']),
            $dados['data_compra'],
            $dados['chave_idempotencia']
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'compra' => $resultado['compra'],
        ], $resultado['reenvio_detectado'] ? 200 : 201);
    }
}
