<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\EventoSaude;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O núcleo do Vertical 11 (Evento de Saúde — a ponte, pra Consumo de
 * Insumo), nascido de VERTICAL-EVENTO-SAUDE.md, traduzindo
 * SCHEMA-CONTRATO-EVENTO-SAUDE.md pra código real. A ponte é literal:
 * registrar() chama ConsumoInsumoService::registrar() de verdade — nenhuma
 * lógica de consumo nova aqui. Diferente de Morte (ponte de algoritmo),
 * chamar o Service real é exatamente o comportamento certo: a baixa de
 * estoque de um Evento de Saúde é idêntica à de qualquer outro consumo.
 */
class EventoSaudeService
{
    public function buscar(int $usuarioId, int $eventoId): ?EventoSaude
    {
        $evento = EventoSaude::find($eventoId);
        if (! $evento) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($evento->fazenda_id)) {
            return null;
        }

        return $evento;
    }

    public function registrar(int $usuarioId, int $fazendaId, array $animalIds, int $insumoId, float $quantidade, string $descricao, string $dataAplicacao, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = EventoSaude::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'evento' => $existente];
        }

        if (empty($animalIds)) {
            throw new DomainException('Um Evento de Saúde precisa de pelo menos um animal.');
        }

        if (trim($descricao) === '') {
            throw new DomainException('Evento de Saúde exige descricao.');
        }

        $animaisEncontrados = Animal::where('fazenda_id', $fazendaId)->whereIn('id', $animalIds)->count();
        if ($animaisEncontrados !== count(array_unique($animalIds))) {
            throw new DomainException('Um ou mais animais informados não pertencem a esta Fazenda.');
        }

        return DB::transaction(function () use ($usuarioId, $fazendaId, $animalIds, $insumoId, $quantidade, $descricao, $dataAplicacao, $chaveIdempotencia) {
            // A própria checagem interna de ConsumoInsumoService
            // (lockForUpdate no Insumo + recheck INV-035) já garante que o
            // estoque nunca fica negativo — herda de graça a defesa já
            // provada, nenhum mecanismo novo.
            $resultadoConsumo = app(ConsumoInsumoService::class)->registrar(
                $usuarioId, $fazendaId, $insumoId, $quantidade, $dataAplicacao, $chaveIdempotencia.':consumo'
            );

            try {
                $evento = EventoSaude::create([
                    'fazenda_id' => $fazendaId,
                    'animal_ids' => array_values($animalIds),
                    'descricao' => $descricao,
                    'consumo_insumo_id' => $resultadoConsumo['consumo']->id,
                    'data_aplicacao' => $dataAplicacao,
                    'chave_idempotencia' => $chaveIdempotencia,
                ]);

                $this->registrarEvento('evento_saude_registrado', $fazendaId, $chaveIdempotencia, [
                    'tipo' => 'evento_saude_registrado', 'evento_saude_id' => $evento->id,
                    'consumo_insumo_id' => $resultadoConsumo['consumo']->id, 'animal_ids' => array_values($animalIds),
                ]);

                return ['reenvio_detectado' => false, 'evento' => $evento];
            } catch (QueryException $e) {
                if ($this->violacaoDeUnicidade($e)) {
                    return ['reenvio_detectado' => true, 'evento' => EventoSaude::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
                }
                throw $e;
            }
        });
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
