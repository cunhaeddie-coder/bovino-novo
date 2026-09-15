<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Models\Venda;
use App\Services\VendaService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Thin wrapper — nenhuma regra de domínio aqui (SCHEMA-CONTRATO-
 * AUTENTICACAO.md §5). Toda decisão real mora em VendaService, já provado
 * (schema+domínio+concorrência real) desde o Vertical 1.
 */
class VendaController extends Controller
{
    public function __construct(private readonly VendaService $vendas) {}

    public function index(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['required', 'integer']]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $vendas = Venda::where('fazenda_id', $dados['fazenda_id'])->orderByDesc('data_venda')->get();

        return response()->json(['vendas' => $vendas]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'animal_ids' => ['required', 'array', 'min:1'],
            'animal_ids.*' => ['integer'],
            'valor_bruto' => ['required', 'numeric'],
            'data_venda' => ['required', 'string'],
            'chave_idempotencia' => ['required', 'string'],
        ]);

        $resultado = $this->vendas->registrar(
            $request->user()->id,
            $dados['fazenda_id'],
            $dados['animal_ids'],
            (float) $dados['valor_bruto'],
            $dados['data_venda'],
            $dados['chave_idempotencia']
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'venda' => $resultado['venda'],
        ], $resultado['reenvio_detectado'] ? 200 : 201);
    }

    public function corrigir(Request $request, int $venda)
    {
        $dados = $request->validate([
            'novos_animal_ids' => ['required', 'array', 'min:1'],
            'novos_animal_ids.*' => ['integer'],
            'novo_valor_bruto' => ['required', 'numeric'],
            'chave_idempotencia' => ['required', 'string'],
        ]);

        $resultado = $this->vendas->corrigir(
            $request->user()->id,
            $venda,
            $dados['novos_animal_ids'],
            (float) $dados['novo_valor_bruto'],
            $dados['chave_idempotencia']
        );

        return response()->json([
            'reenvio_detectado' => $resultado['reenvio_detectado'],
            'venda' => $resultado['venda'],
        ]);
    }
}
