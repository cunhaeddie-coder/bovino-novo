<?php

namespace App\Services;

use App\Models\EventoDominio;
use App\Models\Lote;
use App\Models\Piquete;
use App\Models\TrocaPiquete;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O núcleo do Vertical 15 (Rotação de Pastagem), nascido de
 * VERTICAL-ROTACAO-PASTAGEM.md, traduzindo
 * SCHEMA-CONTRATO-ROTACAO-PASTAGEM.md pra código real. Primeira validação
 * temporal síncrona não-financeira e não-sequencial do Bovino Novo — nenhum
 * mecanismo pré-existente pra reaproveitar (INV-040).
 */
class RotacaoPastagemService
{
    public function buscar(int $usuarioId, int $trocaId): ?TrocaPiquete
    {
        $troca = TrocaPiquete::find($trocaId);
        if (! $troca) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($troca->fazenda_id)) {
            return null;
        }

        return $troca;
    }

    /**
     * @param  array{nome: string, dias_descanso: int}|null  $piqueteNovo  o Piquete de destino, referenciado por id existente OU declarado como novo — nunca os dois, nunca nenhum (mesmo formato de insumo_id/insumo_novo em CompraInsumoService::registrar()).
     */
    public function registrar(int $usuarioId, int $fazendaId, int $loteId, ?int $piqueteOrigemId, ?int $piqueteDestinoId, ?array $piqueteNovo, string $dataTroca, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = TrocaPiquete::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'troca' => $existente];
        }

        if (! Lote::where('fazenda_id', $fazendaId)->where('id', $loteId)->exists()) {
            throw new DomainException("Lote #{$loteId} não encontrado ou não pertence a esta Fazenda.");
        }

        if ($piqueteOrigemId !== null && ! Piquete::where('fazenda_id', $fazendaId)->where('id', $piqueteOrigemId)->exists()) {
            throw new DomainException("Piquete de origem #{$piqueteOrigemId} não encontrado ou não pertence a esta Fazenda.");
        }

        if (! $piqueteDestinoId && ! $piqueteNovo) {
            throw new DomainException('É preciso referenciar um Piquete de destino existente (piquete_destino_id) ou declarar um novo (piquete_novo).');
        }
        if ($piqueteDestinoId && $piqueteNovo) {
            throw new DomainException('Não é possível referenciar um Piquete de destino existente e declarar um novo ao mesmo tempo.');
        }

        if ($piqueteDestinoId && ! Piquete::where('fazenda_id', $fazendaId)->where('id', $piqueteDestinoId)->exists()) {
            throw new DomainException("Piquete de destino #{$piqueteDestinoId} não encontrado ou não pertence a esta Fazenda.");
        }

        // Mesmo achado real já corrigido em Compra de Insumo (insumo_novo):
        // um piquete_novo com nome já existente na Fazenda violaria
        // UNIQUE(fazenda_id, nome) DENTRO da transação, e
        // violacaoDeUnicidade() confundiria isso com colisão de
        // chave_idempotencia. Checagem explícita antes da transação —
        // TOCTOU residual sob concorrência genuína fica pro catch abaixo.
        if ($piqueteNovo) {
            if (Piquete::where('fazenda_id', $fazendaId)->where('nome', $piqueteNovo['nome'])->exists()) {
                throw new DomainException("Piquete \"{$piqueteNovo['nome']}\" já existe nesta Fazenda — referencie por piquete_destino_id, não declare como novo.");
            }
            if (($piqueteNovo['dias_descanso'] ?? 0) <= 0) {
                throw new DomainException('piquete_novo.dias_descanso precisa ser positivo.');
            }
        }

        try {
            return DB::transaction(function () use ($fazendaId, $loteId, $piqueteOrigemId, $piqueteDestinoId, $piqueteNovo, $dataTroca, $chaveIdempotencia) {
                $destino = $piqueteDestinoId
                    ? Piquete::findOrFail($piqueteDestinoId)
                    : Piquete::create([
                        'fazenda_id' => $fazendaId,
                        'nome' => $piqueteNovo['nome'],
                        'dias_descanso' => $piqueteNovo['dias_descanso'],
                    ]);

                // SCHEMA-CONTRATO-ROTACAO-PASTAGEM.md §3 — INV-040: a última
                // vez que qualquer Lote SAIU deste Piquete de destino define
                // se o descanso foi cumprido. Sem histórico de saída, não há
                // violação possível a apurar.
                $ultimaSaida = TrocaPiquete::where('piquete_origem_id', $destino->id)
                    ->orderByDesc('data_troca')
                    ->first();

                $descansoInterrompido = false;
                if ($ultimaSaida) {
                    $diasDesdeASaida = $ultimaSaida->data_troca->diffInDays($dataTroca);
                    $descansoInterrompido = $diasDesdeASaida < $destino->dias_descanso;
                }

                $troca = TrocaPiquete::create([
                    'fazenda_id' => $fazendaId,
                    'lote_id' => $loteId,
                    'piquete_origem_id' => $piqueteOrigemId,
                    'piquete_destino_id' => $destino->id,
                    'data_troca' => $dataTroca,
                    'descanso_interrompido' => $descansoInterrompido,
                    'chave_idempotencia' => $chaveIdempotencia,
                ]);

                $this->registrarEvento('troca_piquete_registrada', $fazendaId, $chaveIdempotencia, [
                    'tipo' => 'troca_piquete_registrada', 'troca_id' => $troca->id,
                    'lote_id' => $loteId, 'piquete_destino_id' => $destino->id,
                    'descanso_interrompido' => $descansoInterrompido,
                ]);

                return ['reenvio_detectado' => false, 'troca' => $troca];
            });
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                $existente = TrocaPiquete::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first();
                if ($existente) {
                    return ['reenvio_detectado' => true, 'troca' => $existente];
                }

                // Mesma classe de TOCTOU já provada em Compra de Insumo
                // (Ataque L): SQLSTATE 23000 sem TrocaPiquete correspondente
                // não é colisão de chave_idempotencia — é outra violação de
                // UNIQUE (dois processos declarando piquete_novo com o mesmo
                // nome ao mesmo tempo). Sem este catch, firstOrFail()
                // vazaria ModelNotFoundException.
                throw new DomainException(
                    'Não foi possível concluir a troca de Piquete — o nome do Piquete novo colidiu com outra operação concorrente. Tente novamente referenciando o Piquete por piquete_destino_id.'
                );
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
