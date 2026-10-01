<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incendio;
use App\Models\Usuario;
use App\Services\IncendioService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Thin wrapper — toda decisão real mora em IncendioService, já provado
 * desde o Vertical 20.
 */
class IncendioController extends Controller
{
    public function __construct(private readonly IncendioService $incendios) {}

    public function index(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['required', 'integer']]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $incendios = Incendio::where('fazenda_id', $dados['fazenda_id'])->orderByDesc('data_incendio')->get();

        return response()->json(['incendios' => $incendios]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'piquete_id' => ['required', 'integer'],
            'data_incendio' => ['required', 'string'],
            'chave_idempotencia' => ['required', 'string'],
        ]);

        $resultado = $this->incendios->registrar(
            $request->user()->id,
            $dados['fazenda_id'],
            $dados['piquete_id'],
            $dados['data_incendio'],
            $dados['chave_idempotencia']
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'incendio' => $resultado['incendio'],
        ], $resultado['reenvio_detectado'] ? 200 : 201);
    }
}
