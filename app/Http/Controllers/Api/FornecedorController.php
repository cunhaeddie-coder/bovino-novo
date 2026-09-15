<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fornecedor;
use App\Models\Usuario;
use DomainException;
use Illuminate\Http\Request;

/**
 * Leitura de apoio pras telas de Compra/Compra de Insumo (seleção de
 * fornecedor). fornecedores.fazenda_id nullable é fornecedor externo
 * (SCHEMA-CONTRATO-MARKETPLACE.md §1) — sempre visível, nunca isolado por
 * Fazenda (é o caso comum: fornecedor não é ator do sistema).
 */
class FornecedorController extends Controller
{
    public function index(Request $request)
    {
        $usuario = Usuario::findOrFail($request->user()->id);

        if ($request->filled('fazenda_id')) {
            $fazendaId = (int) $request->query('fazenda_id');
            if (! $usuario->temRelacaoComFazenda($fazendaId)) {
                throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$fazendaId}.");
            }
        }

        $fornecedores = Fornecedor::whereNull('fazenda_id')->orderBy('nome')->get();

        return response()->json(['fornecedores' => $fornecedores]);
    }
}
