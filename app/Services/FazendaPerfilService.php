<?php

namespace App\Services;

use App\Models\Fazenda;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * VERTICAL-FAZENDA-PERFIL-PUBLICO.md / SCHEMA-CONTRATO-FAZENDA-PERFIL-PUBLICO.md
 * — núcleo do Vertical 24. Publica/edita o perfil de uma Fazenda que o
 * usuário já tem — nunca cria uma Fazenda nova (evita por desenho a
 * ambiguidade POST/PUT do Atual, LAB-SA-024).
 */
class FazendaPerfilService
{
    private const CAMPOS_PERFIL = ['descricao', 'logo_url', 'website', 'raca_principal'];

    public function publicar(int $usuarioId, int $fazendaId, array $dados = []): Fazenda
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        $fazenda = Fazenda::findOrFail($fazendaId);

        $valores = ['ativo' => true];
        foreach (self::CAMPOS_PERFIL as $campo) {
            if (array_key_exists($campo, $dados)) {
                $valores[$campo] = $dados[$campo];
            }
        }

        if ($fazenda->slug === null) {
            $this->salvarComSlugUnico($fazenda, $valores);
        } else {
            $fazenda->update($valores);
        }

        return $fazenda->fresh();
    }

    public function despublicar(int $usuarioId, int $fazendaId): Fazenda
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        $fazenda = Fazenda::findOrFail($fazendaId);
        $fazenda->update(['ativo' => false]);

        return $fazenda->fresh();
    }

    // INV-049 — slug inexistente e Fazenda despublicada respondem
    // exatamente igual (null), nunca distinguindo os dois casos.
    public function perfilPublico(string $slug): ?array
    {
        $fazenda = Fazenda::where('slug', $slug)->where('ativo', true)->first();
        if (! $fazenda) {
            return null;
        }

        return [
            'nome' => $fazenda->nome,
            'estado' => $fazenda->estado,
            'descricao' => $fazenda->descricao,
            'logo_url' => $fazenda->logo_url,
            'website' => $fazenda->website,
            'raca_principal' => $fazenda->raca_principal,
        ];
    }

    // SCHEMA-CONTRATO-FAZENDA-PERFIL-PUBLICO.md §4 — risco de concorrência
    // nomeado: 2 publicar() concorrentes de Fazendas DIFERENTES podem
    // calcular o mesmo slug base (Str::slug() é determinístico). Mesmo
    // padrão de captura+retry já corrigido em KycService::submeter()
    // (Spike 007 Extensão 24) — nunca lockForUpdate aqui, porque o
    // conflito é entre linhas diferentes, não a mesma linha.
    private function salvarComSlugUnico(Fazenda $fazenda, array $valores): void
    {
        $base = Str::slug($fazenda->nome);
        $slug = $base;
        $tentativa = 1;

        while (true) {
            try {
                $fazenda->update(array_merge($valores, ['slug' => $slug]));

                return;
            } catch (QueryException $e) {
                if (! $this->violacaoDeUnicidade($e)) {
                    throw $e;
                }
                $tentativa++;
                $slug = $base.'-'.$tentativa;
            }
        }
    }

    private function garantirRelacaoComFazenda(int $usuarioId, int $fazendaId): void
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($fazendaId)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com a Fazenda {$fazendaId}.");
        }
    }

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
