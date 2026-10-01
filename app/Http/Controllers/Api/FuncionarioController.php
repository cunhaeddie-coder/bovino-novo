<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Funcionario;
use App\Models\Usuario;
use App\Services\FuncionarioService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Thin wrapper — toda decisão real mora em FuncionarioService, já provado
 * desde o Vertical 10 (+ extensão Vertical 32, Acerto de Rescisão).
 */
class FuncionarioController extends Controller
{
    public function __construct(private readonly FuncionarioService $funcionarios) {}

    public function index(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['required', 'integer']]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $funcionarios = Funcionario::where('fazenda_id', $dados['fazenda_id'])
            ->with('acertosRescisao.itens')
            ->orderBy('nome')
            ->get();

        return response()->json(['funcionarios' => $funcionarios]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'nome' => ['required', 'string'],
            'cargo' => ['nullable', 'string'],
            'salario' => ['required', 'numeric'],
            'data_contratacao' => ['required', 'string'],
            'chave_idempotencia' => ['required', 'string'],
        ]);

        $resultado = $this->funcionarios->contratar(
            $request->user()->id,
            $dados['fazenda_id'],
            $dados['nome'],
            $dados['cargo'] ?? null,
            (float) $dados['salario'],
            $dados['data_contratacao'],
            $dados['chave_idempotencia']
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'funcionario' => $resultado['funcionario'],
        ], $resultado['reenvio_detectado'] ? 200 : 201);
    }

    public function desligar(Request $request, int $funcionario)
    {
        $dados = $request->validate(['data_desligamento' => ['required', 'string']]);

        $resultado = $this->funcionarios->desligar($request->user()->id, $funcionario, $dados['data_desligamento']);

        return response()->json(['funcionario' => $resultado]);
    }

    public function registrarAcertoRescisao(Request $request, int $funcionario)
    {
        $dados = $request->validate([
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.nome' => ['required', 'string'],
            'itens.*.valor' => ['required', 'numeric'],
            'itens.*.vencimento' => ['required', 'string'],
            'chave_idempotencia' => ['required', 'string'],
        ]);

        $resultado = $this->funcionarios->registrarCustoDesligamento(
            $request->user()->id,
            $funcionario,
            $dados['itens'],
            $dados['chave_idempotencia']
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'acerto' => $resultado['acerto'],
            'itens' => $resultado['itens'],
        ], $resultado['reenvio_detectado'] ? 200 : 201);
    }
}
