<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrdemFrete;
use App\Models\Usuario;
use App\Services\OrdemFreteService;
use App\Services\PropostaFreteService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Thin wrapper — nenhuma regra de domínio aqui. Toda decisão real mora em
 * OrdemFreteService/PropostaFreteService, já provados desde o Vertical 22.
 * Escopo desta rodada: só o lado Fazenda (solicitar leilão, contratar
 * direto, ver propostas, aceitar, cancelar, concluir) — "dar lance" é o
 * lado Motorista, persona sem login nesta fatia de frontend (decisão via
 * AskUserQuestion, 30/09/2026).
 */
class OrdemFreteController extends Controller
{
    public function __construct(
        private readonly OrdemFreteService $ordens,
        private readonly PropostaFreteService $propostas
    ) {}

    public function index(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['required', 'integer']]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $ordens = OrdemFrete::where('fazenda_id', $dados['fazenda_id'])
            ->with(['propostas.motorista.usuario', 'motorista.usuario'])
            ->orderByDesc('id')
            ->get();

        return response()->json(['ordens_frete' => $ordens]);
    }

    public function solicitar(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'chave_idempotencia' => ['required', 'string'],
        ]);

        $resultado = $this->ordens->solicitar($request->user()->id, $dados['fazenda_id'], $dados['chave_idempotencia']);

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'ordem_frete' => $resultado['ordem_frete'],
        ], $resultado['reenvio_detectado'] ? 200 : 201);
    }

    public function contratarDireto(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'motorista_id' => ['required', 'integer'],
            'valor_frete' => ['required', 'numeric'],
            'chave_idempotencia' => ['required', 'string'],
        ]);

        $resultado = $this->ordens->contratarDireto(
            $request->user()->id,
            $dados['fazenda_id'],
            $dados['motorista_id'],
            (float) $dados['valor_frete'],
            $dados['chave_idempotencia']
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'ordem_frete' => $resultado['ordem_frete'],
        ], $resultado['reenvio_detectado'] ? 200 : 201);
    }

    public function aceitarProposta(Request $request, int $ordemFrete)
    {
        $dados = $request->validate(['proposta_id' => ['required', 'integer']]);

        $resultado = $this->propostas->aceitarProposta($request->user()->id, $ordemFrete, $dados['proposta_id']);

        return response()->json([
            'ordem_frete' => $resultado['ordem_frete'],
            'proposta' => $resultado['proposta'],
        ]);
    }

    public function cancelar(Request $request, int $ordemFrete)
    {
        $resultado = $this->ordens->cancelar($request->user()->id, $ordemFrete);

        return response()->json(['ordem_frete' => $resultado['ordem_frete']]);
    }

    public function concluir(Request $request, int $ordemFrete)
    {
        $resultado = $this->ordens->concluir($request->user()->id, $ordemFrete);

        return response()->json(['ordem_frete' => $resultado['ordem_frete']]);
    }
}
