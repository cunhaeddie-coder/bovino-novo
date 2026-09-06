<?php

namespace App\Services;

use App\Models\ConsumoInsumo;
use App\Models\EventoDominio;
use App\Models\Insumo;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O núcleo do Vertical 5 (Consumo de Insumo), nascido de
 * VERTICAL-CONSUMO-INSUMO.md, traduzindo SCHEMA-CONTRATO-CONSUMO-INSUMO.md
 * pra código real. O vertical mais enxuto até agora — sem ObrigacaoFinanceira,
 * sem FormaPagamento: o custo do Insumo já foi reconhecido na Compra,
 * Consumo é só uma baixa física de recurso (INV-004), que nunca pode deixar
 * o estoque negativo (INV-035).
 */
class ConsumoInsumoService
{
    public function buscar(int $usuarioId, int $consumoId): ?ConsumoInsumo
    {
        $consumo = ConsumoInsumo::find($consumoId);
        if (! $consumo) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($consumo->fazenda_id)) {
            return null;
        }

        return $consumo;
    }

    public function registrar(int $usuarioId, int $fazendaId, int $insumoId, float $quantidade, string $dataConsumo, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = ConsumoInsumo::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'consumo' => $existente];
        }

        // SCHEMA-CONTRATO-CONSUMO-INSUMO.md §3 — quantidade <= 0 nunca é um
        // fato real, mesma disciplina de "preço <= 0 não é Compra válida".
        if ($quantidade <= 0) {
            throw new DomainException("Consumo de Insumo exige quantidade positiva (recebido: {$quantidade}).");
        }

        try {
            return DB::transaction(function () use ($fazendaId, $insumoId, $quantidade, $dataConsumo, $chaveIdempotencia) {
                $insumo = Insumo::where('id', $insumoId)
                    ->where('fazenda_id', $fazendaId)
                    ->lockForUpdate()
                    ->first();

                if (! $insumo) {
                    throw new DomainException("Insumo #{$insumoId} não encontrado ou não pertence a esta Fazenda.");
                }

                // INV-035 — recheck dentro da transação, sob lockForUpdate:
                // consumo nunca deixa o estoque negativo.
                if ((float) $insumo->quantidade < $quantidade) {
                    throw new DomainException(
                        "Consumo de {$quantidade} excede a quantidade disponível ({$insumo->quantidade}) do Insumo #{$insumoId} — nenhum efeito parcial aplicado."
                    );
                }

                $insumo->update(['quantidade' => $insumo->quantidade - $quantidade]);

                $consumo = ConsumoInsumo::create([
                    'fazenda_id' => $fazendaId,
                    'insumo_id' => $insumoId,
                    'quantidade' => $quantidade,
                    'data_consumo' => $dataConsumo,
                    'chave_idempotencia' => $chaveIdempotencia,
                ]);

                $this->registrarEvento('consumo_insumo_registrado', $fazendaId, $chaveIdempotencia, [
                    'tipo' => 'consumo_insumo_registrado', 'insumo_id' => $insumoId,
                    'quantidade' => $quantidade, 'fazenda_id' => $fazendaId,
                ]);

                return [
                    'reenvio_detectado' => false,
                    'consumo' => $consumo,
                    'insumo' => $insumo->fresh(),
                ];
            });
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'consumo' => ConsumoInsumo::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
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
