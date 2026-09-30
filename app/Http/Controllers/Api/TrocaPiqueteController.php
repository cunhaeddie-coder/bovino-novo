<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrocaPiquete;
use App\Models\Usuario;
use App\Services\RotacaoPastagemService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Thin wrapper — toda decisão real mora em RotacaoPastagemService, já
 * provado desde o Vertical 15.
 */
class TrocaPiqueteController extends Controller
{
    public function __construct(private readonly RotacaoPastagemService $trocas) {}

    public function index(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['required', 'integer']]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $trocas = TrocaPiquete::where('fazenda_id', $dados['fazenda_id'])->orderByDesc('data_troca')->get();

        return response()->json(['trocas' => $trocas]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'lote_id' => ['required', 'integer'],
            'piquete_origem_id' => ['nullable', 'integer'],
            'piquete_destino_id' => ['nullable', 'integer'],
            'piquete_novo' => ['nullable', 'array'],
            'piquete_novo.nome' => ['required_with:piquete_novo', 'string'],
            'piquete_novo.dias_descanso' => ['required_with:piquete_novo', 'integer'],
            'data_troca' => ['required', 'string'],
            'chave_idempotencia' => ['required', 'string'],
        ]);

        $resultado = $this->trocas->registrar(
            $request->user()->id,
            $dados['fazenda_id'],
            $dados['lote_id'],
            $dados['piquete_origem_id'] ?? null,
            $dados['piquete_destino_id'] ?? null,
            $dados['piquete_novo'] ?? null,
            $dados['data_troca'],
            $dados['chave_idempotencia']
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'troca' => $resultado['troca'],
        ], $resultado['reenvio_detectado'] ? 200 : 201);
    }
}
