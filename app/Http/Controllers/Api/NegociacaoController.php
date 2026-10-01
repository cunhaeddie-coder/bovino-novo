<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Negociacao;
use App\Models\Usuario;
use App\Services\NegociacaoService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Thin wrapper — toda decisão real mora em NegociacaoService, já provado
 * (ciclo integrado fechado antes desta exposição HTTP). Confirmação em 2
 * fases: confirmarVendedor()/confirmarComprador() são ações separadas,
 * cada uma autorizada pelo lado certo (SCHEMA-CONTRATO-MARKETPLACE.md §5).
 */
class NegociacaoController extends Controller
{
    public function __construct(private readonly NegociacaoService $negociacoes) {}

    public function index(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['required', 'integer']]);
        $fazendaId = (int) $dados['fazenda_id'];

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda($fazendaId)) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$fazendaId}.");
        }

        $negociacoes = Negociacao::where('fazenda_compradora_id', $fazendaId)
            ->orWhereHas('anuncio', fn ($q) => $q->where('fazenda_id', $fazendaId))
            ->with('anuncio.fazenda', 'anuncio.animais', 'fazendaCompradora')
            ->orderByDesc('id')
            ->get();

        return response()->json(['negociacoes' => $negociacoes]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'anuncio_id' => ['required', 'integer'],
            'fazenda_compradora_id' => ['required', 'integer'],
            'preco_proposto' => ['required', 'numeric'],
            'chave_idempotencia' => ['required', 'string'],
        ]);

        $resultado = $this->negociacoes->propor(
            $request->user()->id, $dados['anuncio_id'], $dados['fazenda_compradora_id'],
            (float) $dados['preco_proposto'], $dados['chave_idempotencia']
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'negociacao' => $resultado['negociacao'],
        ], $resultado['reenvio_detectado'] ? 200 : 201);
    }

    public function aceitar(Request $request, int $negociacao)
    {
        $resultado = $this->negociacoes->aceitar($request->user()->id, $negociacao);

        return response()->json(['negociacao' => $resultado['negociacao']]);
    }

    public function confirmarVendedor(Request $request, int $negociacao)
    {
        $resultado = $this->negociacoes->confirmarVendedor($request->user()->id, $negociacao);

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'negociacao' => $resultado['negociacao'],
        ]);
    }

    public function confirmarComprador(Request $request, int $negociacao)
    {
        $resultado = $this->negociacoes->confirmarComprador($request->user()->id, $negociacao);

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'negociacao' => $resultado['negociacao'],
        ]);
    }
}
