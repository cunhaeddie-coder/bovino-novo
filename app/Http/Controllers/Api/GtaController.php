<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gta;
use App\Models\Usuario;
use App\Services\GtaService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Thin wrapper — nenhuma regra de domínio aqui (SCHEMA-CONTRATO-
 * AUTENTICACAO.md §5). Toda decisão real mora em GtaService, já provado
 * (schema+domínio+concorrência real) desde o Vertical 6.
 */
class GtaController extends Controller
{
    public function __construct(private readonly GtaService $gtas) {}

    public function index(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['required', 'integer']]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $gtas = Gta::where('fazenda_id', $dados['fazenda_id'])->orderByDesc('data_emissao')->get();

        return response()->json(['gtas' => $gtas]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'animal_ids' => ['required', 'array', 'min:1'],
            'animal_ids.*' => ['integer'],
            'destino' => ['required', 'string'],
            'valor_bruto' => ['required', 'numeric'],
            'data_emissao' => ['required', 'string'],
            'chave_idempotencia' => ['required', 'string'],
        ]);

        $resultado = $this->gtas->registrar(
            $request->user()->id,
            $dados['fazenda_id'],
            $dados['animal_ids'],
            $dados['destino'],
            count($dados['animal_ids']),
            (float) $dados['valor_bruto'],
            $dados['data_emissao'],
            $dados['chave_idempotencia']
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'gta' => $resultado['gta'],
        ], $resultado['reenvio_detectado'] ? 200 : 201);
    }

    public function concluir(Request $request, int $gta)
    {
        $resultado = $this->gtas->concluir($request->user()->id, $gta);

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'gta' => $resultado['gta'],
        ]);
    }
}
