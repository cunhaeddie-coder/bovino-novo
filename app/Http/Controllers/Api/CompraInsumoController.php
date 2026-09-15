<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompraInsumo;
use App\Models\Usuario;
use App\Services\CompraInsumoService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Thin wrapper — nenhuma regra de domínio aqui. Toda decisão real mora em
 * CompraInsumoService, já provado (schema+domínio+concorrência real) desde
 * o Vertical 3.
 */
class CompraInsumoController extends Controller
{
    public function __construct(private readonly CompraInsumoService $comprasInsumo) {}

    public function index(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['required', 'integer']]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $compras = CompraInsumo::where('fazenda_id', $dados['fazenda_id'])->orderByDesc('data_compra')->get();

        return response()->json(['compras' => $compras]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'fornecedor_id' => ['required', 'integer'],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.insumo_id' => ['nullable', 'integer'],
            'itens.*.insumo_novo.nome' => ['nullable', 'string'],
            'itens.*.quantidade' => ['required', 'numeric'],
            'itens.*.valor_unitario' => ['required', 'numeric'],
            'data_compra' => ['required', 'string'],
            'chave_idempotencia' => ['required', 'string'],
        ]);

        $resultado = $this->comprasInsumo->registrar(
            $request->user()->id,
            $dados['fazenda_id'],
            $dados['fornecedor_id'],
            $dados['itens'],
            $dados['data_compra'],
            $dados['chave_idempotencia']
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'compra' => $resultado['compra'],
        ], $resultado['reenvio_detectado'] ? 200 : 201);
    }
}
