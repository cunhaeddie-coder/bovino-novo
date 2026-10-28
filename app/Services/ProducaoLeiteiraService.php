<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\ProducaoLeiteira;
use App\Models\Usuario;
use App\Services\Concerns\RegistraEventoDominio;
use DomainException;
use Illuminate\Database\QueryException;

/**
 * O núcleo do Vertical 14 (Produção Leiteira), nascido de
 * VERTICAL-PRODUCAO-LEITEIRA.md, traduzindo
 * SCHEMA-CONTRATO-PRODUCAO-LEITEIRA.md pra código real. INSERT puro, sem
 * lockForUpdate() (mesma categoria de risco de Nascimento/Folha de
 * Pagamento/Arrendamento).
 *
 * Decisão do produtor (28/10/2026): o lançamento normal é o total de todas
 * as vacas juntas (animal_id null); uma vaca separada só raramente. O leite
 * pro bezerro saiu: ele mama no peito, ninguém mede.
 */
class ProducaoLeiteiraService
{
    use RegistraEventoDominio;

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

    public function registrar(int $usuarioId, int $fazendaId, ?int $animalId, string $dataProducao, float $quantidadeTotal, float $quantidadeVendida, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = ProducaoLeiteira::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'producao' => $existente];
        }

        if ($animalId !== null && ! Animal::where('fazenda_id', $fazendaId)->where('id', $animalId)->exists()) {
            throw new DomainException("Animal #{$animalId} não encontrado ou não pertence a esta Fazenda.");
        }

        if ($quantidadeTotal <= 0) {
            throw new DomainException("Produção Leiteira exige quantidade_total positiva (recebido: {$quantidadeTotal}).");
        }

        if ($quantidadeVendida < 0) {
            throw new DomainException('quantidade_vendida não pode ser negativa.');
        }

        // Achado real ao expor via HTTP (30/09/2026): esta regra só era
        // validada pelo guard do Model (INV-039), que lança LogicException
        // crua — não capturada pelo handler de DomainException em
        // bootstrap/app.php, virava 500 em vez de 422. Mesma disciplina de
        // toda checagem de domínio no Service: falhar limpo antes da
        // transação, nunca deixar o guard de infraestrutura ser a única
        // defesa.
        if (round($quantidadeVendida, 2) > round($quantidadeTotal, 2)) {
            throw new DomainException('quantidade_vendida não pode exceder quantidade_total (INV-039).');
        }

        try {
            $producao = ProducaoLeiteira::create([
                'fazenda_id' => $fazendaId,
                'animal_id' => $animalId,
                'data_producao' => $dataProducao,
                'quantidade_total' => $quantidadeTotal,
                'quantidade_vendida' => $quantidadeVendida,
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

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
