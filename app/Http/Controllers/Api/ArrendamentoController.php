<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Arrendamento;
use App\Models\Usuario;
use App\Services\ArrendamentoService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Thin wrapper — toda decisão real mora em ArrendamentoService, já provado
 * desde o Vertical 12.
 */
class ArrendamentoController extends Controller
{
    public function __construct(private readonly ArrendamentoService $arrendamentos) {}

    public function index(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['required', 'integer']]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $arrendamentos = Arrendamento::where('fazenda_id', $dados['fazenda_id'])
            ->with('parcelas')
            ->orderByDesc('data_inicio')
            ->get();

        return response()->json(['arrendamentos' => $arrendamentos]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'fornecedor_id' => ['required', 'integer'],
            'valor_total' => ['required', 'numeric'],
            'periodicidade' => ['required', 'string', 'in:mensal,anual'],
            'data_inicio' => ['required', 'string'],
            'data_fim' => ['required', 'string'],
            'chave_idempotencia' => ['required', 'string'],
        ]);

        $resultado = $this->arrendamentos->registrar(
            $request->user()->id,
            $dados['fazenda_id'],
            $dados['fornecedor_id'],
            (float) $dados['valor_total'],
            $dados['periodicidade'],
            $dados['data_inicio'],
            $dados['data_fim'],
            $dados['chave_idempotencia']
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'arrendamento' => $resultado['arrendamento']->load('parcelas'),
        ], $resultado['reenvio_detectado'] ? 200 : 201);
    }

    public function gerarProximaParcela(Request $request, int $arrendamento)
    {
        $resultado = $this->arrendamentos->gerarProximaParcela($request->user()->id, $arrendamento);

        return response()->json(['parcela' => $resultado['parcela']], 201);
    }
}
