<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Nascimento;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O núcleo do Vertical 9 (Rebanho — Nascimento), nascido de
 * VERTICAL-NASCIMENTO.md, traduzindo SCHEMA-CONTRATO-NASCIMENTO.md pra
 * código real. Diferente dos 8 verticais anteriores, não é ponte nem
 * algoritmo reaproveitado — é a primeira vez que o Bovino Novo cria Animal
 * sem nenhuma contrapartida financeira nem recálculo de agregado. Cada
 * "filhote" é um array opcional ['mae_id' => ?int, 'peso_nascimento' =>
 * ?float] — nascimento em lote (sem mãe) e individual (com mãe) usam o
 * mesmo fluxo, mae_id sempre opcional por item (SCHEMA-CONTRATO-NASCIMENTO.md §2).
 */
class NascimentoService
{
    public function buscar(int $usuarioId, int $nascimentoId): ?Nascimento
    {
        $nascimento = Nascimento::find($nascimentoId);
        if (! $nascimento) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($nascimento->fazenda_id)) {
            return null;
        }

        return $nascimento;
    }

    public function registrar(int $usuarioId, int $fazendaId, array $filhotes, string $dataNascimento, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = Nascimento::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'nascimento' => $existente];
        }

        if (empty($filhotes)) {
            throw new DomainException('Um Nascimento precisa de pelo menos um animal.');
        }

        // Mãe, quando informada, precisa ser um Animal real da mesma
        // Fazenda — nenhuma validação de status (a mãe pode já não estar
        // ativa, o vínculo é histórico, não uma condição de negócio).
        $maeIds = array_filter(array_column($filhotes, 'mae_id'));
        if (! empty($maeIds)) {
            $maesEncontradas = Animal::where('fazenda_id', $fazendaId)->whereIn('id', $maeIds)->count();
            if ($maesEncontradas !== count(array_unique($maeIds))) {
                throw new DomainException('Uma ou mais mães informadas não pertencem a esta Fazenda.');
            }
        }

        try {
            return DB::transaction(function () use ($fazendaId, $filhotes, $dataNascimento, $chaveIdempotencia) {
                $animalIds = [];
                foreach ($filhotes as $filhote) {
                    $animal = Animal::create([
                        'fazenda_id' => $fazendaId,
                        'lote_id' => null,
                        'custo_aquisicao' => 0,
                        'status' => 'ativo',
                        'tipo_origem' => 'nascido_na_fazenda',
                        'mae_id' => $filhote['mae_id'] ?? null,
                        'peso_nascimento' => $filhote['peso_nascimento'] ?? null,
                    ]);
                    $animalIds[] = $animal->id;
                }

                $nascimento = Nascimento::create([
                    'fazenda_id' => $fazendaId,
                    'animal_ids' => $animalIds,
                    'data_nascimento' => $dataNascimento,
                    'chave_idempotencia' => $chaveIdempotencia,
                ]);

                $this->registrarEvento('nascimento_registrado', $fazendaId, $chaveIdempotencia, [
                    'tipo' => 'nascimento_registrado', 'nascimento_id' => $nascimento->id, 'animal_ids' => $animalIds,
                ]);

                return ['reenvio_detectado' => false, 'nascimento' => $nascimento];
            });
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'nascimento' => Nascimento::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
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
