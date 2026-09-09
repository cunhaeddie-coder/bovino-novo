<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\ReclassificacaoFinalidade;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O núcleo do Vertical 16 (Reclassificação em Lote — finalidade), nascido de
 * VERTICAL-RECLASSIFICACAO.md, traduzindo
 * SCHEMA-CONTRATO-RECLASSIFICACAO.md pra código real. Mesma forma exata de
 * ReclassificacaoCategoriaService — fato separado, decisão do produtor
 * (LAB-FA-012, corrige o mesmo rate limit, agora em finalidade).
 */
class ReclassificacaoFinalidadeService
{
    public function buscar(int $usuarioId, int $reclassificacaoId): ?ReclassificacaoFinalidade
    {
        $reclassificacao = ReclassificacaoFinalidade::find($reclassificacaoId);
        if (! $reclassificacao) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($reclassificacao->fazenda_id)) {
            return null;
        }

        return $reclassificacao;
    }

    public function registrar(int $usuarioId, int $fazendaId, array $animalIds, string $finalidadeNova, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = ReclassificacaoFinalidade::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'reclassificacao' => $existente];
        }

        if (empty($animalIds)) {
            throw new DomainException('Uma Reclassificação de Finalidade precisa de pelo menos um animal.');
        }

        if (trim($finalidadeNova) === '') {
            throw new DomainException('finalidade_nova não pode ser vazia.');
        }

        try {
            return DB::transaction(function () use ($fazendaId, $animalIds, $finalidadeNova, $chaveIdempotencia) {
                $existentesAtivos = Animal::where('fazenda_id', $fazendaId)
                    ->whereIn('id', $animalIds)
                    ->where('status', 'ativo')
                    ->count();

                if ($existentesAtivos !== count($animalIds)) {
                    throw new DomainException(
                        'Um ou mais animais pedidos não pertencem a esta Fazenda ou já não estão ativos — nenhum efeito parcial aplicado.'
                    );
                }

                // INV-010 — uma única operação, não N.
                Animal::where('fazenda_id', $fazendaId)
                    ->whereIn('id', $animalIds)
                    ->where('status', 'ativo')
                    ->update(['finalidade' => $finalidadeNova]);

                $reclassificacao = ReclassificacaoFinalidade::create([
                    'fazenda_id' => $fazendaId,
                    'animal_ids' => array_values($animalIds),
                    'finalidade_nova' => $finalidadeNova,
                    'chave_idempotencia' => $chaveIdempotencia,
                ]);

                $this->registrarEvento('reclassificacao_finalidade_registrada', $fazendaId, $chaveIdempotencia, [
                    'tipo' => 'reclassificacao_finalidade_registrada', 'reclassificacao_id' => $reclassificacao->id,
                    'animal_ids' => array_values($animalIds), 'finalidade_nova' => $finalidadeNova,
                ]);

                return ['reenvio_detectado' => false, 'reclassificacao' => $reclassificacao];
            });
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'reclassificacao' => ReclassificacaoFinalidade::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
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
