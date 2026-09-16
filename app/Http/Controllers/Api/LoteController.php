<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lote;
use App\Models\Usuario;
use DomainException;
use Illuminate\Http\Request;

/**
 * Leitura de apoio pra tela de Lotes (módulo Rebanho, wireframe
 * "Navegação Completa" aprovado 16/09/2026) — nenhuma escrita aqui,
 * isolamento por Fazenda igual a todo Service de domínio (INV-029).
 * Lote não tem nome/título (SCHEMA-CONTRATO-COMPRA.md) — é só um
 * agregado de qtd_animais/custo_aquisicao, referenciado por id.
 */
class LoteController extends Controller
{
    public function index(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
        ]);

        $usuario = Usuario::findOrFail($request->user()->id);
        if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
            throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
        }

        $lotes = Lote::where('fazenda_id', $dados['fazenda_id'])->orderBy('id')->get();

        $lotes->each(function (Lote $lote) {
            $lote->custo_medio = $lote->qtd_animais > 0
                ? round($lote->custo_aquisicao / $lote->qtd_animais, 2)
                : null;
        });

        return response()->json(['lotes' => $lotes]);
    }
}
