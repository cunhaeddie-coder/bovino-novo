<?php

namespace App\Services;

use App\Models\Conta;
use App\Models\Titular;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA-CONTRATO-CARTEIRA.md — núcleo do Vertical 27 (Carteira/Conta),
 * parte 1. Uma Conta 'externa' nasce por ação explícita do produtor
 * (criarExterna); a Conta 'bovino' nasce sozinha, sob demanda, só quando o
 * consumidor de outbox (LancamentoService) precisa dela pela primeira vez.
 */
class ContaService
{
    public function buscar(int $usuarioId, int $contaId): ?Conta
    {
        $conta = Conta::find($contaId);
        if (! $conta) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComTitular($conta->titular_id)) {
            return null;
        }

        return $conta;
    }

    public function criarExterna(int $usuarioId, int $titularId, string $nome, string $chaveIdempotencia): array
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComTitular($titularId)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com o Titular {$titularId}.");
        }

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if (trim($nome) === '') {
            throw new DomainException('nome não pode ser vazio.');
        }

        if ($existente = Conta::where('titular_id', $titularId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'conta' => $existente];
        }

        try {
            $conta = Conta::create(['titular_id' => $titularId, 'tipo' => 'externa', 'nome' => $nome, 'chave_idempotencia' => $chaveIdempotencia]);

            return ['reenvio_detectado' => false, 'conta' => $conta];
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'conta' => Conta::where('titular_id', $titularId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    // SCHEMA-CONTRATO-CARTEIRA.md §4 — chamado só pelo consumidor de outbox
    // (LancamentoService), nunca por uma ação direta do usuário. INV-055:
    // no máximo 1 Conta 'bovino' por Titular. Diferente de Kyc/FazendaPerfil/
    // Titular (que têm uma coluna UNIQUE de verdade protegendo o valor que
    // colide), 'bovino' Contas não têm nenhuma coluna assim — o único jeito
    // real de impedir 2 Contas 'bovino' concorrentes pro mesmo Titular é
    // serializar via lock no próprio Titular (a linha-pai), não um
    // catch(QueryException) que nunca dispararia (não existe UNIQUE(titular_id)
    // condicionado a tipo='bovino' portável entre MySQL/SQLite).
    public function buscarOuCriarContaBovino(int $titularId): Conta
    {
        return DB::transaction(function () use ($titularId) {
            Titular::where('id', $titularId)->lockForUpdate()->firstOrFail();

            $contaBovino = Conta::where('titular_id', $titularId)->where('tipo', 'bovino')->first();
            if ($contaBovino) {
                return $contaBovino;
            }

            return Conta::create(['titular_id' => $titularId, 'tipo' => 'bovino', 'nome' => 'Conta Bovino']);
        });
    }

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
