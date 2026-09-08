<?php

namespace App\Services;

use App\Models\Arrendamento;
use App\Models\EventoDominio;
use App\Models\FormaPagamento;
use App\Models\Fornecedor;
use App\Models\ObrigacaoFinanceira;
use App\Models\ParcelaArrendamento;
use App\Models\Usuario;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O núcleo do Vertical 12 (Arrendamento), nascido de
 * VERTICAL-ARRENDAMENTO.md, traduzindo SCHEMA-CONTRATO-ARRENDAMENTO.md pra
 * código real. Segunda implementação real de INV-016 (recorrência como
 * capacidade da plataforma, primeira foi Folha de Pagamento): reaproveita
 * ObrigacaoFinanceira/FormaPagamento já provados, sem nenhum mecanismo
 * financeiro novo. Diferente de Folha de Pagamento, o número de parcelas é
 * finito e calculado a partir de data_inicio/data_fim/periodicidade — nunca
 * fixo, corrige o achado real do LAB-FA-020 (periodicidade anual gerando
 * parcelas mensais).
 *
 * Mesma disciplina do achado do Spike 007 (Evento de Saúde, Extensão 12):
 * nenhuma chamada aqui envolve outro Service com seu próprio mecanismo de
 * idempotência via DB::transaction()+catch — ObrigacaoFinanceira/
 * FormaPagamento são criadas diretamente, dentro da própria transação deste
 * Service, evitando o risco de transação aninhada.
 */
class ArrendamentoService
{
    public function buscar(int $usuarioId, int $arrendamentoId): ?Arrendamento
    {
        $arrendamento = Arrendamento::find($arrendamentoId);
        if (! $arrendamento) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($arrendamento->fazenda_id)) {
            return null;
        }

        return $arrendamento;
    }

    public function registrar(int $usuarioId, int $fazendaId, int $fornecedorId, float $valorTotal, string $periodicidade, string $dataInicio, string $dataFim, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = Arrendamento::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'arrendamento' => $existente];
        }

        if (! Fornecedor::find($fornecedorId)) {
            throw new DomainException("Fornecedor #{$fornecedorId} não encontrado.");
        }

        if ($valorTotal <= 0) {
            throw new DomainException("Arrendamento exige valor_total positivo (recebido: {$valorTotal}).");
        }

        if (! in_array($periodicidade, ['mensal', 'anual'], true)) {
            throw new DomainException("periodicidade precisa ser 'mensal' ou 'anual' (recebido: {$periodicidade}).");
        }

        if (Carbon::parse($dataFim)->lessThanOrEqualTo(Carbon::parse($dataInicio))) {
            throw new DomainException('data_fim precisa ser posterior a data_inicio.');
        }

        try {
            $arrendamento = Arrendamento::create([
                'fazenda_id' => $fazendaId,
                'fornecedor_id' => $fornecedorId,
                'valor_total' => $valorTotal,
                'periodicidade' => $periodicidade,
                'data_inicio' => $dataInicio,
                'data_fim' => $dataFim,
                'chave_idempotencia' => $chaveIdempotencia,
            ]);

            $this->registrarEvento('arrendamento_registrado', $fazendaId, $chaveIdempotencia, [
                'tipo' => 'arrendamento_registrado', 'arrendamento_id' => $arrendamento->id, 'fornecedor_id' => $fornecedorId,
            ]);

            return ['reenvio_detectado' => false, 'arrendamento' => $arrendamento];
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'arrendamento' => Arrendamento::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    public function gerarProximaParcela(int $usuarioId, int $arrendamentoId): array
    {
        $arrendamento = Arrendamento::find($arrendamentoId);
        if (! $arrendamento) {
            throw new DomainException("Arrendamento #{$arrendamentoId} não encontrado.");
        }

        $this->garantirRelacaoComFazenda($usuarioId, $arrendamento->fazenda_id);

        $totalParcelas = $this->calcularNumeroTotalParcelas($arrendamento);
        $jaGeradas = ParcelaArrendamento::where('arrendamento_id', $arrendamentoId)->count();

        if ($jaGeradas >= $totalParcelas) {
            throw new DomainException("Arrendamento #{$arrendamentoId} já tem todas as {$totalParcelas} parcelas geradas.");
        }

        $numeroParcela = $jaGeradas + 1;
        $valorParcela = $numeroParcela === $totalParcelas
            ? round((float) $arrendamento->valor_total - round(((float) $arrendamento->valor_total / $totalParcelas), 2) * ($totalParcelas - 1), 2)
            : round((float) $arrendamento->valor_total / $totalParcelas, 2);

        try {
            return DB::transaction(function () use ($arrendamento, $numeroParcela, $valorParcela) {
                $parcela = ParcelaArrendamento::create([
                    'fazenda_id' => $arrendamento->fazenda_id,
                    'arrendamento_id' => $arrendamento->id,
                    'numero_parcela' => $numeroParcela,
                    'valor' => $valorParcela,
                    'data_geracao' => now(),
                ]);

                $obrigacao = ObrigacaoFinanceira::create([
                    'fazenda_id' => $arrendamento->fazenda_id,
                    'parcela_arrendamento_id' => $parcela->id,
                    'direcao' => 'a_pagar',
                    'valor' => $valorParcela,
                ]);

                $vencimento = $arrendamento->periodicidade === 'anual'
                    ? Carbon::parse($arrendamento->data_inicio)->addYears($numeroParcela)->toDateString()
                    : Carbon::parse($arrendamento->data_inicio)->addMonths($numeroParcela)->toDateString();

                FormaPagamento::create([
                    'obrigacao_financeira_id' => $obrigacao->id,
                    'nome' => "Parcela {$numeroParcela}",
                    'unidade' => 'dinheiro',
                    'valor' => $valorParcela,
                    'data' => now(),
                    'vencimento' => $vencimento,
                    'pago_em' => null,
                ]);

                $this->registrarEvento('parcela_arrendamento_gerada', $arrendamento->fazenda_id, "arrendamento:{$arrendamento->id}:parcela:{$numeroParcela}:evento", [
                    'tipo' => 'parcela_arrendamento_gerada', 'arrendamento_id' => $arrendamento->id,
                    'numero_parcela' => $numeroParcela, 'obrigacao_financeira_id' => $obrigacao->id,
                ]);

                return ['parcela' => $parcela];
            });
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['parcela' => ParcelaArrendamento::where('arrendamento_id', $arrendamento->id)->where('numero_parcela', $numeroParcela)->firstOrFail(), 'reenvio_detectado' => true];
            }
            throw $e;
        }
    }

    private function calcularNumeroTotalParcelas(Arrendamento $arrendamento): int
    {
        $inicio = Carbon::parse($arrendamento->data_inicio);
        $fim = Carbon::parse($arrendamento->data_fim);

        return max(1, $arrendamento->periodicidade === 'anual' ? $inicio->diffInYears($fim) : $inicio->diffInMonths($fim));
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
