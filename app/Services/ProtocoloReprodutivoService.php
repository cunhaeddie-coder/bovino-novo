<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\EtapaProtocolo;
use App\Models\EventoDominio;
use App\Models\ProtocoloReprodutivo;
use App\Models\Usuario;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O núcleo do Vertical 13 (Protocolo Reprodutivo — IATF), nascido de
 * VERTICAL-PROTOCOLO-REPRODUTIVO.md, traduzindo
 * SCHEMA-CONTRATO-PROTOCOLO-REPRODUTIVO.md pra código real. Primeira
 * implementação real de INV-014 (protocolo multi-etapa é uma entidade, não
 * eventos soltos). Categoria de risco genuinamente nova: nenhuma
 * consequência financeira/estoque — só máquina de estados sequencial, mais
 * parecida com o recálculo de Lote de MorteService (leitura-e-escrita real
 * sobre um agregado) do que com os INSERTs puros de Nascimento/Folha de
 * Pagamento/Arrendamento.
 */
class ProtocoloReprodutivoService
{
    private const ETAPAS_IATF = ['implante', 'prostaglandina', 'retirada_ia'];

    public function buscar(int $usuarioId, int $protocoloId): ?ProtocoloReprodutivo
    {
        $protocolo = ProtocoloReprodutivo::find($protocoloId);
        if (! $protocolo) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($protocolo->fazenda_id)) {
            return null;
        }

        return $protocolo;
    }

    public function iniciar(int $usuarioId, int $fazendaId, array $animalIds, string $dataInicio, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = ProtocoloReprodutivo::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'protocolo' => $existente];
        }

        if (empty($animalIds)) {
            throw new DomainException('Um Protocolo Reprodutivo precisa de pelo menos um animal.');
        }

        $animaisEncontrados = Animal::where('fazenda_id', $fazendaId)->whereIn('id', $animalIds)->count();
        if ($animaisEncontrados !== count(array_unique($animalIds))) {
            throw new DomainException('Um ou mais animais informados não pertencem a esta Fazenda.');
        }

        try {
            $protocolo = DB::transaction(function () use ($fazendaId, $animalIds, $dataInicio, $chaveIdempotencia) {
                $protocolo = ProtocoloReprodutivo::create([
                    'fazenda_id' => $fazendaId,
                    'animal_ids' => array_values($animalIds),
                    'data_inicio' => $dataInicio,
                    'status' => 'em_andamento',
                    'chave_idempotencia' => $chaveIdempotencia,
                ]);

                $inicio = Carbon::parse($dataInicio);
                EtapaProtocolo::create([
                    'fazenda_id' => $fazendaId, 'protocolo_reprodutivo_id' => $protocolo->id,
                    'tipo' => 'implante', 'data_prevista' => $inicio, 'data_realizada' => $inicio,
                ]);
                EtapaProtocolo::create([
                    'fazenda_id' => $fazendaId, 'protocolo_reprodutivo_id' => $protocolo->id,
                    'tipo' => 'prostaglandina', 'data_prevista' => $inicio->copy()->addDays(7),
                ]);
                EtapaProtocolo::create([
                    'fazenda_id' => $fazendaId, 'protocolo_reprodutivo_id' => $protocolo->id,
                    'tipo' => 'retirada_ia', 'data_prevista' => $inicio->copy()->addDays(9),
                ]);

                $this->registrarEvento('protocolo_reprodutivo_iniciado', $fazendaId, $chaveIdempotencia, [
                    'tipo' => 'protocolo_reprodutivo_iniciado', 'protocolo_id' => $protocolo->id, 'animal_ids' => array_values($animalIds),
                ]);

                return $protocolo;
            });

            return ['reenvio_detectado' => false, 'protocolo' => $protocolo];
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'protocolo' => ProtocoloReprodutivo::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    public function cumprirEtapa(int $usuarioId, int $protocoloId, string $tipoEtapa, string $dataRealizada): array
    {
        $protocolo = ProtocoloReprodutivo::find($protocoloId);
        if (! $protocolo) {
            throw new DomainException("ProtocoloReprodutivo #{$protocoloId} não encontrado.");
        }

        $this->garantirRelacaoComFazenda($usuarioId, $protocolo->fazenda_id);

        if (! in_array($tipoEtapa, self::ETAPAS_IATF, true)) {
            throw new DomainException("tipo_etapa precisa ser 'implante', 'prostaglandina' ou 'retirada_ia' (recebido: {$tipoEtapa}).");
        }

        return DB::transaction(function () use ($protocoloId, $tipoEtapa, $dataRealizada) {
            $protocolo = ProtocoloReprodutivo::lockForUpdate()->findOrFail($protocoloId);

            $etapa = EtapaProtocolo::where('protocolo_reprodutivo_id', $protocoloId)
                ->where('tipo', $tipoEtapa)
                ->lockForUpdate()
                ->firstOrFail();

            if ($etapa->data_realizada !== null) {
                return ['reenvio_detectado' => true, 'protocolo' => $protocolo, 'etapa' => $etapa];
            }

            if ($protocolo->status !== 'em_andamento') {
                throw new DomainException("Protocolo #{$protocoloId} não está em andamento (status atual: {$protocolo->status}).");
            }

            $etapa->update(['data_realizada' => $dataRealizada]);

            $etapasCumpridas = EtapaProtocolo::where('protocolo_reprodutivo_id', $protocoloId)
                ->whereNotNull('data_realizada')
                ->count();

            if ($etapasCumpridas === count(self::ETAPAS_IATF)) {
                $protocolo->update(['status' => 'concluido']);
            }

            $this->registrarEvento('etapa_protocolo_cumprida', $protocolo->fazenda_id, "protocolo:{$protocoloId}:etapa:{$tipoEtapa}:evento", [
                'tipo' => 'etapa_protocolo_cumprida', 'protocolo_id' => $protocoloId,
                'tipo_etapa' => $tipoEtapa, 'status_protocolo' => $protocolo->fresh()->status,
            ]);

            return ['reenvio_detectado' => false, 'protocolo' => $protocolo->fresh(), 'etapa' => $etapa->fresh()];
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
