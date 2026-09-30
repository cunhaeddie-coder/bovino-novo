<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventoSaude;
use App\Models\Usuario;
use App\Services\EventoSaudeService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Thin wrapper — nenhuma regra de domínio aqui (SCHEMA-CONTRATO-
 * AUTENTICACAO.md §5). Toda decisão real mora em EventoSaudeService, já
 * provado (schema+domínio+concorrência real) desde o Vertical 11.
 */
class EventoSaudeController extends Controller
{
    public function __construct(private readonly EventoSaudeService $eventos) {}

    public function index(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['required', 'integer']]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $eventos = EventoSaude::where('fazenda_id', $dados['fazenda_id'])->orderByDesc('data_aplicacao')->get();

        return response()->json(['eventos' => $eventos]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'animal_ids' => ['required', 'array', 'min:1'],
            'animal_ids.*' => ['integer'],
            'insumo_id' => ['required', 'integer'],
            'quantidade' => ['required', 'numeric'],
            'descricao' => ['required', 'string'],
            'data_aplicacao' => ['required', 'string'],
            'chave_idempotencia' => ['required', 'string'],
            'certificado' => ['nullable', 'string'],
            'tipo_vacina' => ['nullable', 'string'],
            'epoca' => ['nullable', 'string'],
        ]);

        $resultado = $this->eventos->registrar(
            $request->user()->id,
            $dados['fazenda_id'],
            $dados['animal_ids'],
            $dados['insumo_id'],
            (float) $dados['quantidade'],
            $dados['descricao'],
            $dados['data_aplicacao'],
            $dados['chave_idempotencia'],
            $dados['certificado'] ?? null,
            $dados['tipo_vacina'] ?? null,
            $dados['epoca'] ?? null
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'evento' => $resultado['evento'],
        ], $resultado['reenvio_detectado'] ? 200 : 201);
    }
}
