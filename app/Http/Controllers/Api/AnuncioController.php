<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Anuncio;
use App\Models\Usuario;
use App\Services\AnuncioService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Thin wrapper — toda decisão real mora em AnuncioService, já provado
 * desde o Vertical 23 (KYC). Sem fazenda_id, lista o mercado (anúncios
 * ativos de qualquer Fazenda, leitura pública); com fazenda_id, lista só
 * os anúncios da própria Fazenda (qualquer status), isolado por usuário.
 */
class AnuncioController extends Controller
{
    public function __construct(private readonly AnuncioService $anuncios) {}

    public function index(Request $request)
    {
        $dados = $request->validate(['fazenda_id' => ['nullable', 'integer']]);

        if (! empty($dados['fazenda_id'])) {
            $usuario = Usuario::findOrFail($request->user()->id);
            if (! $usuario->temRelacaoComFazenda((int) $dados['fazenda_id'])) {
                throw new DomainException("Operação recusada: usuário sem relação com a Fazenda {$dados['fazenda_id']}.");
            }

            $anuncios = Anuncio::where('fazenda_id', $dados['fazenda_id'])
                ->with('animais')
                ->orderByDesc('id')
                ->get();

            return response()->json(['anuncios' => $anuncios]);
        }

        $anuncios = Anuncio::where('status', 'ativo')
            ->with('fazenda', 'animais')
            ->orderByDesc('id')
            ->get();

        return response()->json(['anuncios' => $anuncios]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'fazenda_id' => ['required', 'integer'],
            'preco_total' => ['required', 'numeric'],
            'animal_ids' => ['required', 'array', 'min:1'],
            'animal_ids.*' => ['integer'],
        ]);

        $anuncio = $this->anuncios->publicar(
            $request->user()->id, $dados['fazenda_id'], (float) $dados['preco_total'], $dados['animal_ids']
        );

        return response()->json(['anuncio' => $anuncio->load('animais')], 201);
    }
}
