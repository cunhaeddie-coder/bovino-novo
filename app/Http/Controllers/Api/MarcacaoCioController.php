<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarcacaoCio;
use App\Models\Usuario;
use App\Services\MarcacaoCioService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Thin wrapper — toda decisão real mora em MarcacaoCioService, já provado
 * desde o Vertical 17.
 */
class MarcacaoCioController extends Controller
{
    public function __construct(private readonly MarcacaoCioService $marcacoes) {}

    public function index(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['required', 'integer']]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $marcacoes = MarcacaoCio::where('fazenda_id', $dados['fazenda_id'])->orderByDesc('data_marcacao')->get();

        return response()->json(['marcacoes' => $marcacoes]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'vaca_id' => ['required', 'integer'],
            'rufiao_id' => ['required', 'integer'],
            'data_marcacao' => ['required', 'string'],
            'chave_idempotencia' => ['required', 'string'],
        ]);

        $resultado = $this->marcacoes->registrar(
            $request->user()->id,
            $dados['fazenda_id'],
            $dados['vaca_id'],
            $dados['rufiao_id'],
            $dados['data_marcacao'],
            $dados['chave_idempotencia']
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'marcacao' => $resultado['marcacao'],
        ], $resultado['reenvio_detectado'] ? 200 : 201);
    }
}
