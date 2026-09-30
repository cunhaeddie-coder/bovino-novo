<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProtocoloReprodutivo;
use App\Models\Usuario;
use App\Services\ProtocoloReprodutivoService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Thin wrapper — nenhuma regra de domínio aqui (SCHEMA-CONTRATO-
 * AUTENTICACAO.md §5). Toda decisão real mora em ProtocoloReprodutivoService,
 * já provado desde o Vertical 13.
 */
class ProtocoloReprodutivoController extends Controller
{
    public function __construct(private readonly ProtocoloReprodutivoService $protocolos) {}

    public function index(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['required', 'integer']]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $protocolos = ProtocoloReprodutivo::where('fazenda_id', $dados['fazenda_id'])
            ->with('etapas')
            ->orderByDesc('data_inicio')
            ->get();

        return response()->json(['protocolos' => $protocolos]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'animal_ids' => ['required', 'array', 'min:1'],
            'animal_ids.*' => ['integer'],
            'data_inicio' => ['required', 'string'],
            'chave_idempotencia' => ['required', 'string'],
        ]);

        $resultado = $this->protocolos->iniciar(
            $request->user()->id,
            $dados['fazenda_id'],
            $dados['animal_ids'],
            $dados['data_inicio'],
            $dados['chave_idempotencia']
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'protocolo' => $resultado['protocolo']->load('etapas'),
        ], $resultado['reenvio_detectado'] ? 200 : 201);
    }

    public function cumprirEtapa(Request $request, int $protocolo)
    {
        $dados = $request->validate([
            'tipo_etapa' => ['required', 'string'],
            'data_realizada' => ['required', 'string'],
        ]);

        $resultado = $this->protocolos->cumprirEtapa(
            $request->user()->id,
            $protocolo,
            $dados['tipo_etapa'],
            $dados['data_realizada']
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'protocolo' => $resultado['protocolo']->load('etapas'),
        ]);
    }
}
