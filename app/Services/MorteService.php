<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\Lote;
use App\Models\Morte;
use App\Models\Usuario;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O núcleo do Vertical 7 (Rebanho — Morte), nascido de VERTICAL-MORTE.md,
 * traduzindo SCHEMA-CONTRATO-MORTE.md pra código real. Diferente de
 * Marketplace/GTA, NUNCA chama VendaService — isso fabricaria um resultado
 * financeiro (CPV, receita, ObrigacaoFinanceira) que a Morte, por decisão
 * direta do produtor, nunca tem. Replica o mesmo algoritmo de recálculo
 * proporcional de Lote que VendaService::registrar() já tem provado
 * (INV-001, "venda, morte, perda"), como código próprio.
 */
class MorteService
{
    public function buscar(int $usuarioId, int $morteId): ?Morte
    {
        $morte = Morte::find($morteId);
        if (! $morte) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($morte->fazenda_id)) {
            return null;
        }

        return $morte;
    }

    public function registrar(int $usuarioId, int $fazendaId, array $animalIds, string $causa, string $dataMorte, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = Morte::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'morte' => $existente];
        }

        if (empty($animalIds)) {
            throw new DomainException('Uma Morte precisa de pelo menos um animal.');
        }

        // INV-038 — causa sempre obrigatória, "desconhecida" é valor válido,
        // string vazia nunca é.
        if (trim($causa) === '') {
            throw new DomainException('Morte exige causa registrada — "desconhecida" é um valor válido, vazio não.');
        }

        try {
            return DB::transaction(function () use ($fazendaId, $animalIds, $causa, $dataMorte, $chaveIdempotencia) {
                $animais = Animal::where('fazenda_id', $fazendaId)
                    ->whereIn('id', $animalIds)
                    ->where('status', 'ativo')
                    ->lockForUpdate()
                    ->get();

                if ($animais->count() !== count($animalIds)) {
                    // Mesmo padrão de VendaService::registrar() — pode ser
                    // reenvio que perdeu a corrida pelo lock, não pedido
                    // inválido.
                    $concorrente = Morte::where('fazenda_id', $fazendaId)
                        ->where('chave_idempotencia', $chaveIdempotencia)
                        ->lockForUpdate()
                        ->first();
                    if ($concorrente) {
                        return ['reenvio_detectado' => true, 'morte' => $concorrente];
                    }

                    throw new DomainException(
                        'Um ou mais animais pedidos não pertencem a esta Fazenda ou já não estão ativos — nenhum efeito parcial aplicado.'
                    );
                }

                $dataSaida = Carbon::parse($dataMorte)->toDateString();
                $animais->each(fn (Animal $a) => $a->update(['status' => 'morto', 'data_saida' => $dataSaida]));

                // SCHEMA-CONTRATO-MORTE.md §3/§4 — mesmo algoritmo de
                // VendaService::registrar(), código próprio: animal com
                // lote_id recalcula o Lote proporcionalmente; animal sem
                // lote_id (custo_aquisicao próprio) não tem agregado a
                // ajustar.
                foreach ($animais->whereNotNull('lote_id')->groupBy('lote_id') as $loteId => $doLote) {
                    $lote = Lote::where('id', $loteId)->lockForUpdate()->firstOrFail();
                    $qtdSaida = $doLote->count();
                    $custoUnitario = $lote->custo_aquisicao / $lote->qtd_animais;
                    $custoRemovido = round($custoUnitario * $qtdSaida, 2);
                    $lote->update([
                        'qtd_animais' => $lote->qtd_animais - $qtdSaida,
                        'custo_aquisicao' => $lote->custo_aquisicao - $custoRemovido,
                    ]);
                }

                $morte = Morte::create([
                    'fazenda_id' => $fazendaId,
                    'animal_ids' => array_values($animalIds),
                    'causa' => $causa,
                    'data_morte' => $dataMorte,
                    'chave_idempotencia' => $chaveIdempotencia,
                ]);

                $this->registrarEvento('morte_registrada', $fazendaId, $chaveIdempotencia, [
                    'tipo' => 'morte_registrada', 'morte_id' => $morte->id,
                    'animal_ids' => array_values($animalIds), 'causa' => $causa,
                ]);

                return ['reenvio_detectado' => false, 'morte' => $morte];
            });
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'morte' => Morte::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
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
