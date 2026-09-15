<?php

namespace App\Services;

use App\Models\Comissao;
use App\Models\Indicacao;
use App\Models\Parceiro;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA-CONTRATO-PARCEIROS-COMISSAO.md — núcleo do Vertical 28
 * (Parceiros/Comissão), parte 2. `confirmarConversao()` reaproveita
 * exatamente o padrão de FormaPagamentoService::liquidar() —
 * lockForUpdate()+recheck de um campo "já processado", nunca
 * catch(QueryException) (não há UNIQUE nenhum protegendo reenvio aqui).
 */
class IndicacaoService
{
    public function buscar(int $indicacaoId): ?Indicacao
    {
        return Indicacao::find($indicacaoId);
    }

    public function indicar(int $parceiroId, string $clienteNome, ?string $clienteDocumento, string $chaveIdempotencia): array
    {
        if (! Parceiro::find($parceiroId)) {
            throw new DomainException("Parceiro #{$parceiroId} não encontrado.");
        }

        if (trim($clienteNome) === '') {
            throw new DomainException('cliente_nome não pode ser vazio.');
        }

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = Indicacao::where('parceiro_id', $parceiroId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'indicacao' => $existente];
        }

        try {
            $indicacao = Indicacao::create([
                'parceiro_id' => $parceiroId,
                'cliente_nome' => $clienteNome,
                'cliente_documento' => $clienteDocumento,
                'data_indicacao' => now(),
                'chave_idempotencia' => $chaveIdempotencia,
                'confirmada_em' => null,
            ]);

            return ['reenvio_detectado' => false, 'indicacao' => $indicacao];
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'indicacao' => Indicacao::where('parceiro_id', $parceiroId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    // SCHEMA-CONTRATO-PARCEIROS-COMISSAO.md §4 — numeroParcelas restrito a
    // {2, 3} (única faixa com evidência real, pergunta 75). Todas as
    // parcelas idênticas em valor (mesma mensalidade, mesmo percentual,
    // cada mês) — nenhuma evidência de que a 2ª/3ª parcela difira da 1ª.
    public function confirmarConversao(int $usuarioId, int $indicacaoId, float $valorMensalidade, float $percentual, int $numeroParcelas): array
    {
        $this->garantirAdministrador($usuarioId);

        if (! in_array($numeroParcelas, [2, 3], true)) {
            throw new DomainException("numeroParcelas precisa ser 2 ou 3 (recebido: {$numeroParcelas}).");
        }

        if ($valorMensalidade <= 0) {
            throw new DomainException("valorMensalidade precisa ser positivo (recebido: {$valorMensalidade}).");
        }

        if ($percentual <= 0) {
            throw new DomainException("percentual precisa ser positivo (recebido: {$percentual}).");
        }

        return DB::transaction(function () use ($indicacaoId, $valorMensalidade, $percentual, $numeroParcelas) {
            $indicacao = Indicacao::where('id', $indicacaoId)->lockForUpdate()->first();
            if (! $indicacao) {
                throw new DomainException("Indicação #{$indicacaoId} não encontrada.");
            }

            if ($indicacao->confirmada_em !== null) {
                return ['ja_confirmada' => true, 'indicacao' => $indicacao];
            }

            $indicacao->update(['confirmada_em' => now()]);

            $comissoes = [];
            $valorParcela = round($valorMensalidade * $percentual / 100, 2);
            for ($numero = 1; $numero <= $numeroParcelas; $numero++) {
                $comissoes[] = Comissao::create([
                    'indicacao_id' => $indicacao->id,
                    'numero_parcela' => $numero,
                    'valor' => $valorParcela,
                    'percentual_aplicado' => $percentual,
                    'valor_mensalidade_base' => $valorMensalidade,
                ]);
            }

            return ['ja_confirmada' => false, 'indicacao' => $indicacao->fresh(), 'comissoes' => $comissoes];
        });
    }

    private function garantirAdministrador(int $usuarioId): void
    {
        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->eh_administrador) {
            throw new DomainException("Operação recusada: usuário {$usuarioId} não é administrador.");
        }
    }

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
