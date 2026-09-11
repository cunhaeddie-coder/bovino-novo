<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Pesagem;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O núcleo do Vertical 19 (Pesagem), nascido de VERTICAL-PESAGEM.md,
 * traduzindo SCHEMA-CONTRATO-PESAGEM.md pra código real. Mesma categoria de
 * Produção Leiteira (Vertical 14) — fato individual por Animal, INSERT
 * puro, sem lockForUpdate(). Reproduz o RESULTADO já demonstrado pelo
 * Bovino Atual em LAB-FA-013 (lote real, acesso igual de dono/vaqueiro via
 * INV-029, GMD correto) — nunca o mecanismo (Regra Zero).
 */
class PesagemService
{
    public function buscar(int $usuarioId, int $pesagemId): ?Pesagem
    {
        $pesagem = Pesagem::find($pesagemId);
        if (! $pesagem) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($pesagem->fazenda_id)) {
            return null;
        }

        return $pesagem;
    }

    public function registrar(int $usuarioId, int $fazendaId, int $animalId, float $peso, string $dataPesagem, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = Pesagem::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'pesagem' => $existente];
        }

        if (! Animal::where('fazenda_id', $fazendaId)->where('id', $animalId)->exists()) {
            throw new DomainException("Animal #{$animalId} não encontrado ou não pertence a esta Fazenda.");
        }

        if ($peso <= 0) {
            throw new DomainException("Pesagem exige peso positivo (recebido: {$peso}).");
        }

        try {
            $pesagem = Pesagem::create([
                'fazenda_id' => $fazendaId,
                'animal_id' => $animalId,
                'peso' => $peso,
                'data_pesagem' => $dataPesagem,
                'chave_idempotencia' => $chaveIdempotencia,
            ]);

            $this->registrarEvento('pesagem_registrada', $fazendaId, $chaveIdempotencia, [
                'tipo' => 'pesagem_registrada', 'pesagem_id' => $pesagem->id,
                'animal_id' => $animalId, 'peso' => $peso, 'data_pesagem' => $dataPesagem,
            ]);

            return ['reenvio_detectado' => false, 'pesagem' => $pesagem];
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'pesagem' => Pesagem::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    /**
     * SCHEMA-CONTRATO-PESAGEM.md §3 — N registros independentes (cada um
     * com sua própria chave_idempotencia) dentro de uma única transação:
     * todo animal_id é validado antes de criar qualquer linha, o lote
     * inteiro é criado ou nenhum item é, nunca sucesso parcial. $pesagens é
     * uma lista de ['animal_id' => int, 'peso' => float, 'chave_idempotencia' => string].
     */
    public function registrarLote(int $usuarioId, int $fazendaId, array $pesagens, string $dataPesagem): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (empty($pesagens)) {
            throw new DomainException('Um lote de Pesagem precisa de pelo menos um item.');
        }

        $animalIds = array_column($pesagens, 'animal_id');
        $animaisEncontrados = Animal::where('fazenda_id', $fazendaId)->whereIn('id', $animalIds)->count();
        if ($animaisEncontrados !== count(array_unique($animalIds))) {
            throw new DomainException('Um ou mais animais informados não pertencem a esta Fazenda.');
        }

        return DB::transaction(function () use ($usuarioId, $fazendaId, $pesagens, $dataPesagem) {
            $resultados = [];
            foreach ($pesagens as $item) {
                $resultados[] = $this->registrar(
                    $usuarioId, $fazendaId, (int) $item['animal_id'], (float) $item['peso'],
                    $dataPesagem, (string) $item['chave_idempotencia']
                );
            }

            return $resultados;
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
