<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfirmacaoPrenhez;
use App\Models\Usuario;
use App\Services\ConfirmacaoPrenhezService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Thin wrapper — toda decisão real mora em ConfirmacaoPrenhezService, já
 * provado desde o Vertical 17.
 */
class ConfirmacaoPrenhezController extends Controller
{
    public function __construct(private readonly ConfirmacaoPrenhezService $confirmacoes) {}

    public function index(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['required', 'integer']]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $confirmacoes = ConfirmacaoPrenhez::where('fazenda_id', $dados['fazenda_id'])->orderByDesc('data_confirmacao')->get();

        return response()->json(['confirmacoes' => $confirmacoes]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'vaca_id' => ['required', 'integer'],
            'resultado' => ['required', 'string', 'in:positivo,negativo'],
            'tipo_exame' => ['required', 'string'],
            'data_confirmacao' => ['required', 'string'],
            'chave_idempotencia' => ['required', 'string'],
        ]);

        $resultado = $this->confirmacoes->registrar(
            $request->user()->id,
            $dados['fazenda_id'],
            $dados['vaca_id'],
            $dados['resultado'],
            $dados['tipo_exame'],
            $dados['data_confirmacao'],
            $dados['chave_idempotencia']
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'confirmacao' => $resultado['confirmacao'],
        ], $resultado['reenvio_detectado'] ? 200 : 201);
    }
}
