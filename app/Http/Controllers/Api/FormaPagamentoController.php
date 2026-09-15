<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FormaPagamento;
use App\Models\Usuario;
use App\Services\FormaPagamentoService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Thin wrapper — nenhuma regra de domínio aqui. Toda decisão real mora em
 * FormaPagamentoService, já provado (schema+domínio+concorrência real)
 * desde o Vertical Forma de Pagamento.
 */
class FormaPagamentoController extends Controller
{
    public function __construct(private readonly FormaPagamentoService $formasPagamento) {}

    public function index(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'status' => ['nullable', 'in:pendente,pago'],
        ]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $query = FormaPagamento::with('obrigacaoFinanceira')
            ->whereHas('obrigacaoFinanceira', fn ($q) => $q->where('fazenda_id', $dados['fazenda_id']));

        if (($dados['status'] ?? null) === 'pendente') {
            $query->whereNull('pago_em');
        } elseif (($dados['status'] ?? null) === 'pago') {
            $query->whereNotNull('pago_em');
        }

        return response()->json(['formas_pagamento' => $query->orderBy('vencimento')->get()]);
    }

    public function liquidar(Request $request, int $formaPagamento)
    {
        $dados = $request->validate([
            'animal_id' => ['nullable', 'integer'],
            'cotacao_arroba' => ['nullable', 'numeric'],
        ]);

        $resultado = $this->formasPagamento->liquidar(
            $request->user()->id,
            $formaPagamento,
            $dados['animal_id'] ?? null,
            isset($dados['cotacao_arroba']) ? (float) $dados['cotacao_arroba'] : null
        );

        return response()->json([
            'ja_liquidada' => $resultado['ja_liquidada'],
            'forma_pagamento' => $resultado['forma_pagamento'],
        ]);
    }

    public function update(Request $request, int $formaPagamento)
    {
        $dados = $request->validate([
            'nome' => ['nullable', 'string'],
            'valor' => ['nullable', 'numeric'],
            'unidade' => ['nullable', 'in:dinheiro,arroba'],
            'vencimento' => ['nullable', 'string'],
        ]);

        $forma = $this->formasPagamento->editar($request->user()->id, $formaPagamento, $dados);

        return response()->json(['forma_pagamento' => $forma]);
    }
}
