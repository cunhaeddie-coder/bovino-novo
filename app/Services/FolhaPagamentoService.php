<?php

namespace App\Services;

use App\Models\EventoDominio;
use App\Models\FolhaPagamento;
use App\Models\FormaPagamento;
use App\Models\Funcionario;
use App\Models\ObrigacaoFinanceira;
use App\Models\Usuario;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O núcleo do Vertical 10 (Folha de Pagamento), nascido de
 * VERTICAL-FOLHA-PAGAMENTO.md, traduzindo SCHEMA-CONTRATO-FOLHA-PAGAMENTO.md
 * pra código real. Primeira implementação real de INV-016: reaproveita
 * ObrigacaoFinanceira/FormaPagamento já provados (Vertical Forma de
 * Pagamento), sem nenhum mecanismo financeiro novo — só que aqui a
 * FormaPagamento nasce PENDENTE (não à vista), paga depois via
 * FormaPagamentoService::liquidar() já existente, sem alteração nele.
 */
class FolhaPagamentoService
{
    public function gerarMes(int $usuarioId, int $fazendaId, string $mesReferencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        $funcionariosAtivos = Funcionario::where('fazenda_id', $fazendaId)->where('status', 'ativo')->get();

        $geradas = [];
        $puladas = [];

        foreach ($funcionariosAtivos as $funcionario) {
            try {
                $resultado = DB::transaction(function () use ($funcionario, $fazendaId, $mesReferencia) {
                    $folha = FolhaPagamento::create([
                        'fazenda_id' => $fazendaId,
                        'funcionario_id' => $funcionario->id,
                        'mes_referencia' => $mesReferencia,
                        'valor' => $funcionario->salario,
                        'data_geracao' => now(),
                    ]);

                    $obrigacao = ObrigacaoFinanceira::create([
                        'fazenda_id' => $fazendaId,
                        'folha_pagamento_id' => $folha->id,
                        'direcao' => 'a_pagar',
                        'valor' => $funcionario->salario,
                    ]);

                    $vencimento = Carbon::createFromFormat('Y-m', $mesReferencia)->endOfMonth()->toDateString();

                    FormaPagamento::create([
                        'obrigacao_financeira_id' => $obrigacao->id,
                        'nome' => 'Salário',
                        'unidade' => 'dinheiro',
                        'valor' => $funcionario->salario,
                        'data' => now(),
                        'vencimento' => $vencimento,
                        'pago_em' => null,
                    ]);

                    $this->registrarEvento('folha_pagamento_gerada', $fazendaId, "folha:{$funcionario->id}:{$mesReferencia}:evento", [
                        'tipo' => 'folha_pagamento_gerada', 'funcionario_id' => $funcionario->id,
                        'folha_pagamento_id' => $folha->id, 'mes_referencia' => $mesReferencia,
                    ]);

                    return $folha;
                });

                $geradas[] = $resultado;
            } catch (QueryException $e) {
                if ($this->violacaoDeUnicidade($e)) {
                    $puladas[] = $funcionario->id;

                    continue;
                }
                throw $e;
            }
        }

        return ['geradas' => $geradas, 'puladas' => $puladas];
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
            'chave_idempotencia' => $chaveIdempotenciaDoFato,
            'payload' => $payload,
            'status_consequencia' => 'pendente',
        ]);
    }

    private function violacaoDeUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
