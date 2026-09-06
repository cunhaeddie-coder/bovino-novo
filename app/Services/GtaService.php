<?php

namespace App\Services;

use App\Models\EventoDominio;
use App\Models\Gta;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O núcleo do Vertical 6 (GTA — só a ponte), nascido de VERTICAL-GTA.md,
 * traduzindo SCHEMA-CONTRATO-GTA.md pra código real. A ponte é literal:
 * concluir() chama VendaService::registrar() de verdade — nenhuma lógica de
 * venda nova aqui. Diferente do Marketplace, a GTA é emitida unilateralmente
 * (nenhuma Fazenda compradora a autorizar), então cabe numa única transação,
 * sem confirmação em 2 fases.
 */
class GtaService
{
    public function buscar(int $usuarioId, int $gtaId): ?Gta
    {
        $gta = Gta::find($gtaId);
        if (! $gta) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($gta->fazenda_id)) {
            return null;
        }

        return $gta;
    }

    public function registrar(int $usuarioId, int $fazendaId, array $animalIds, string $destino, int $quantidadeDeclarada, float $valorBruto, string $dataEmissao, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = Gta::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'gta' => $existente];
        }

        if (empty($animalIds)) {
            throw new DomainException('Uma GTA precisa de pelo menos um animal.');
        }

        // SCHEMA-CONTRATO-GTA.md §3 — a checagem de valor/quantidade positiva
        // fica aqui, fora do guard de Model (que só verifica a CORRESPONDÊNCIA
        // entre quantidade_declarada e animal_ids — INV-036).
        if ($valorBruto <= 0) {
            throw new DomainException("GTA exige valor_bruto positivo (recebido: {$valorBruto}).");
        }

        try {
            $gta = Gta::create([
                'fazenda_id' => $fazendaId,
                'animal_ids' => array_values($animalIds),
                'destino' => $destino,
                'quantidade_declarada' => $quantidadeDeclarada,
                'valor_bruto' => $valorBruto,
                'status' => 'emitida',
                'data_emissao' => $dataEmissao,
                'chave_idempotencia' => $chaveIdempotencia,
            ]);

            return ['reenvio_detectado' => false, 'gta' => $gta];
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'gta' => Gta::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    public function concluir(int $usuarioId, int $gtaId): array
    {
        $gta = Gta::find($gtaId);
        if (! $gta) {
            throw new DomainException("GTA #{$gtaId} não encontrada.");
        }

        $this->garantirRelacaoComFazenda($usuarioId, $gta->fazenda_id);

        return DB::transaction(function () use ($usuarioId, $gtaId) {
            $gta = Gta::lockForUpdate()->findOrFail($gtaId);

            if ($gta->status === 'concluida') {
                return ['reenvio_detectado' => true, 'gta' => $gta];
            }

            if ($gta->status !== 'emitida') {
                throw new DomainException("Conclusão de GTA exige status=emitida (atual: {$gta->status}).");
            }

            // A própria checagem interna de VendaService (Animais ativo +
            // lockForUpdate) já garante que os animais ainda pertencem à
            // Fazenda e não foram vendidos por nenhum canal — herda de graça
            // a defesa contra concorrência (mesmo mecanismo que Marketplace
            // já herdou).
            $resultadoVenda = app(VendaService::class)->registrar(
                $usuarioId,
                $gta->fazenda_id,
                $gta->animal_ids,
                (float) $gta->valor_bruto,
                now()->format('Y-m-d H:i:s'),
                $gta->chave_idempotencia.':venda'
            );

            $gta->update([
                'venda_id' => $resultadoVenda['venda']->id,
                'status' => 'concluida',
                'data_conclusao' => now(),
            ]);

            $this->registrarEvento('gta_concluida', $gta->fazenda_id, $gta->chave_idempotencia, [
                'tipo' => 'gta_concluida', 'gta_id' => $gta->id, 'venda_id' => $resultadoVenda['venda']->id,
            ]);

            return ['reenvio_detectado' => false, 'gta' => $gta->fresh(), 'venda' => $resultadoVenda['venda']];
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
