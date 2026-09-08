<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\EventoDominio;
use App\Models\ProducaoLeiteira;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;

/**
 * O núcleo do Vertical 14 (Produção Leiteira), nascido de
 * VERTICAL-PRODUCAO-LEITEIRA.md, traduzindo
 * SCHEMA-CONTRATO-PRODUCAO-LEITEIRA.md pra código real. Primeira medição
 * periódica por Animal individual do Bovino Novo — nenhum mecanismo
 * pré-existente pra reaproveitar. INSERT puro, sem lockForUpdate() (mesma
 * categoria de risco de Nascimento/Folha de Pagamento/Arrendamento).
 */
class ProducaoLeiteiraService
{
    public function buscar(int $usuarioId, int $producaoId): ?ProducaoLeiteira
    {
        $producao = ProducaoLeiteira::find($producaoId);
        if (! $producao) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($producao->fazenda_id)) {
            return null;
        }

        return $producao;
    }

    public function registrar(int $usuarioId, int $fazendaId, int $animalId, string $dataProducao, float $quantidadeTotal, float $quantidadeVendida, float $quantidadeBezerro, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = ProducaoLeiteira::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'producao' => $existente];
        }

        if (! Animal::where('fazenda_id', $fazendaId)->where('id', $animalId)->exists()) {
            throw new DomainException("Animal #{$animalId} não encontrado ou não pertence a esta Fazenda.");
        }

        if ($quantidadeTotal <= 0) {
            throw new DomainException("Produção Leiteira exige quantidade_total positiva (recebido: {$quantidadeTotal}).");
        }

        if ($quantidadeVendida < 0 || $quantidadeBezerro < 0) {
            throw new DomainException('quantidade_vendida e quantidade_bezerro não podem ser negativas.');
        }

        try {
            $producao = ProducaoLeiteira::create([
                'fazenda_id' => $fazendaId,
                'animal_id' => $animalId,
                'data_producao' => $dataProducao,
                'quantidade_total' => $quantidadeTotal,
                'quantidade_vendida' => $quantidadeVendida,
                'quantidade_bezerro' => $quantidadeBezerro,
                'chave_idempotencia' => $chaveIdempotencia,
            ]);

            $this->registrarEvento('producao_leiteira_registrada', $fazendaId, $chaveIdempotencia, [
                'tipo' => 'producao_leiteira_registrada', 'producao_id' => $producao->id,
                'animal_id' => $animalId, 'quantidade_total' => $quantidadeTotal,
            ]);

            return ['reenvio_detectado' => false, 'producao' => $producao];
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'producao' => ProducaoLeiteira::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
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
