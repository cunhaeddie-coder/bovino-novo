<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\ReclassificacaoCategoria;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O núcleo do Vertical 16 (Reclassificação em Lote — categoria), nascido de
 * VERTICAL-RECLASSIFICACAO.md, traduzindo
 * SCHEMA-CONTRATO-RECLASSIFICACAO.md pra código real. Primeira implementação
 * real de INV-010 ("operação em massa é uma operação, não N operações") —
 * corrige LAB-FA-011 (rate limit real ao reclassificar 300 animais
 * individualmente). Categoria de risco genuinamente nova: primeiro UPDATE em
 * massa sobre N linhas de uma entidade já existente (Animal), nenhum dos 15
 * verticais anteriores fez isso. §4 do schema contract deixa
 * explicitamente em aberto se lockForUpdate() é necessário — não assumido
 * aqui, resposta fica pro Spike 007.
 */
class ReclassificacaoCategoriaService
{
    public function buscar(int $usuarioId, int $reclassificacaoId): ?ReclassificacaoCategoria
    {
        $reclassificacao = ReclassificacaoCategoria::find($reclassificacaoId);
        if (! $reclassificacao) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($reclassificacao->fazenda_id)) {
            return null;
        }

        return $reclassificacao;
    }

    public function registrar(int $usuarioId, int $fazendaId, array $animalIds, string $categoriaNova, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = ReclassificacaoCategoria::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'reclassificacao' => $existente];
        }

        if (empty($animalIds)) {
            throw new DomainException('Uma Reclassificação de Categoria precisa de pelo menos um animal.');
        }

        if (trim($categoriaNova) === '') {
            throw new DomainException('categoria_nova não pode ser vazia.');
        }

        try {
            return DB::transaction(function () use ($fazendaId, $animalIds, $categoriaNova, $chaveIdempotencia) {
                $existentesAtivos = Animal::where('fazenda_id', $fazendaId)
                    ->whereIn('id', $animalIds)
                    ->where('status', 'ativo')
                    ->count();

                if ($existentesAtivos !== count($animalIds)) {
                    throw new DomainException(
                        'Um ou mais animais pedidos não pertencem a esta Fazenda ou já não estão ativos — nenhum efeito parcial aplicado.'
                    );
                }

                // INV-010 — uma única operação, não N: um UPDATE em massa,
                // nunca um laço de N chamadas individuais.
                Animal::where('fazenda_id', $fazendaId)
                    ->whereIn('id', $animalIds)
                    ->where('status', 'ativo')
                    ->update(['categoria' => $categoriaNova]);

                $reclassificacao = ReclassificacaoCategoria::create([
                    'fazenda_id' => $fazendaId,
                    'animal_ids' => array_values($animalIds),
                    'categoria_nova' => $categoriaNova,
                    'chave_idempotencia' => $chaveIdempotencia,
                ]);

                $this->registrarEvento('reclassificacao_categoria_registrada', $fazendaId, $chaveIdempotencia, [
                    'tipo' => 'reclassificacao_categoria_registrada', 'reclassificacao_id' => $reclassificacao->id,
                    'animal_ids' => array_values($animalIds), 'categoria_nova' => $categoriaNova,
                ]);

                return ['reenvio_detectado' => false, 'reclassificacao' => $reclassificacao];
            });
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'reclassificacao' => ReclassificacaoCategoria::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    private function garantirRelacaoComFazenda(int $usuarioId, int $fazendaId): void
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($fazendaId)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com a Fazenda {$fazendaId}.");
        }
    }

    private function registrarEvento(string $tipo, int $fazendaId, string $chaveIdempotenciaDoFato, array $payload): EventoDominio
    {
        return EventoDominio::create([
            'tipo' => $tipo,
            'fazenda_id' => $fazendaId,
            'chave_idempotencia' => $chaveIdempotenciaDoFato.':evento',
            'payload' => $payload,
            'status_consequencia' => 'pendente',
        ]);
    }

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
