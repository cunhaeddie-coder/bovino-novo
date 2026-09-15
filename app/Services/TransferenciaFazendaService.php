<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Fazenda;
use App\Models\TransferenciaAnimal;
use App\Models\TransferenciaFazenda;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA-CONTRATO-TRANSFERENCIA-FAZENDA.md — núcleo do Vertical 26. Formaliza
 * em código a exceção nova de INV-022 (VERTICAL-TITULAR.md): mover um Animal
 * entre Fazendas do MESMO Titular é reorganização interna, nunca gera
 * receita/CPV/imposto. Mesmo padrão de Compra/Marketplace: o animal na
 * Fazenda destino é um registro NOVO, nunca a mesma linha com fazenda_id
 * trocado.
 */
class TransferenciaFazendaService
{
    public function buscar(int $usuarioId, int $transferenciaId): ?TransferenciaFazenda
    {
        $transferencia = TransferenciaFazenda::find($transferenciaId);
        if (! $transferencia) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($transferencia->fazenda_origem_id)
            && ! $usuario->temRelacaoComFazenda($transferencia->fazenda_destino_id)) {
            return null;
        }

        return $transferencia;
    }

    /**
     * @param  int[]  $animalIds  animais individuais (nunca vinculados a um
     *                            Lote — VERTICAL-TRANSFERENCIA-FAZENDA.md §1)
     */
    public function transferir(int $usuarioId, int $fazendaOrigemId, int $fazendaDestinoId, array $animalIds, string $chaveIdempotencia): array
    {
        $usuario = Usuario::findOrFail($usuarioId);

        // Decisão do produtor (VERTICAL-TRANSFERENCIA-FAZENDA.md §1) — exige
        // relação com as DUAS Fazendas, mais estrita que o padrão de 1
        // Fazenda usado em Venda/Compra: aqui há 2 partes, mas nenhuma
        // "contraparte externa" (é o mesmo Titular).
        if (! $usuario->temRelacaoComFazenda($fazendaOrigemId)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com a Fazenda de origem {$fazendaOrigemId}.");
        }
        if (! $usuario->temRelacaoComFazenda($fazendaDestinoId)) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} sem relação com a Fazenda de destino {$fazendaDestinoId}.");
        }

        if ($fazendaOrigemId === $fazendaDestinoId) {
            throw new DomainException('Fazenda de origem e destino não podem ser a mesma.');
        }

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = TransferenciaFazenda::where('fazenda_origem_id', $fazendaOrigemId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'transferencia' => $existente];
        }

        if (empty($animalIds)) {
            throw new DomainException('Uma Transferência precisa de pelo menos um animal.');
        }

        $fazendaOrigem = Fazenda::findOrFail($fazendaOrigemId);
        $fazendaDestino = Fazenda::findOrFail($fazendaDestinoId);

        // INV-050 — só entre Fazendas do MESMO Titular, nunca nulo. Entre
        // Titulares diferentes (ou sem Titular), a regra original de
        // INV-022 continua valendo: sempre exige evento comercial formal.
        if ($fazendaOrigem->titular_id === null || $fazendaOrigem->titular_id !== $fazendaDestino->titular_id) {
            throw new DomainException(
                'Transferência sem evento comercial só é permitida entre Fazendas do mesmo Titular. '
                .'Fazendas sem Titular vinculado ou de Titulares diferentes precisam de uma Venda/Compra formal.'
            );
        }

        try {
            return DB::transaction(function () use ($fazendaOrigemId, $fazendaDestinoId, $animalIds, $chaveIdempotencia) {
                $animaisOrigem = Animal::whereIn('id', $animalIds)
                    ->where('fazenda_id', $fazendaOrigemId)
                    ->lockForUpdate()
                    ->get();

                if ($animaisOrigem->count() !== count($animalIds)) {
                    throw new DomainException('Um ou mais animais não pertencem à Fazenda de origem informada.');
                }

                // INV-051 — Animal de Lote fica fora do corte mínimo (custo
                // agregado não sabe ser dividido). Tudo ou nada: um único
                // animal inválido recusa a transferência inteira.
                foreach ($animaisOrigem as $animal) {
                    if ($animal->status !== 'ativo') {
                        throw new DomainException("Animal #{$animal->id} não está ativo na Fazenda de origem (status atual: {$animal->status}).");
                    }
                    if ($animal->lote_id !== null) {
                        throw new DomainException("Animal #{$animal->id} pertence a um Lote — transferência de Lote fora do corte mínimo.");
                    }
                }

                $transferencia = TransferenciaFazenda::create([
                    'fazenda_origem_id' => $fazendaOrigemId,
                    'fazenda_destino_id' => $fazendaDestinoId,
                    'chave_idempotencia' => $chaveIdempotencia,
                    'data_transferencia' => now(),
                ]);

                $animaisDestino = [];
                foreach ($animaisOrigem as $animalOrigem) {
                    $animalOrigem->update(['status' => 'transferido', 'data_saida' => now()->toDateString()]);

                    // Categoria/finalidade/mae_id/peso_nascimento não são
                    // herdados (VERTICAL-TRANSFERENCIA-FAZENDA.md §3.6/§7) —
                    // são atributos de manejo/genealogia local, não da
                    // identidade financeira do animal.
                    $animalDestino = Animal::create([
                        'fazenda_id' => $fazendaDestinoId,
                        'lote_id' => null,
                        'custo_aquisicao' => $animalOrigem->custo_aquisicao,
                        'status' => 'ativo',
                        'raca' => $animalOrigem->raca,
                        'tipo_origem' => $animalOrigem->tipo_origem,
                    ]);

                    TransferenciaAnimal::create([
                        'transferencia_id' => $transferencia->id,
                        'animal_origem_id' => $animalOrigem->id,
                        'animal_destino_id' => $animalDestino->id,
                    ]);

                    $animaisDestino[] = $animalDestino;
                }

                return [
                    'reenvio_detectado' => false,
                    'transferencia' => $transferencia,
                    'animais_origem' => $animaisOrigem->all(),
                    'animais_destino' => $animaisDestino,
                ];
            });
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'transferencia' => TransferenciaFazenda::where('fazenda_origem_id', $fazendaOrigemId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
