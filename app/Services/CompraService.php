<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\EventoDominio;
use App\Models\Fornecedor;
use App\Models\ObrigacaoFinanceira;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O núcleo do Vertical 2 (Compra), nascido de VERTICAL-COMPRA.md, traduzindo
 * SCHEMA-CONTRATO-COMPRA.md pra código real. Reaproveita o mesmo mecanismo
 * genérico já provado pra Venda (isolamento, idempotência, núcleo atômico,
 * outbox) — a diferença estrutural real é que Compra CRIA animais novos,
 * nunca referencia animais existentes (diferente de VendaService).
 */
class CompraService
{
    public function buscar(int $usuarioId, int $compraId): ?Compra
    {
        $compra = Compra::find($compraId);
        if (! $compra) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($compra->fazenda_id)) {
            return null;
        }

        return $compra;
    }

    /**
     * @param  float[]  $valoresPorAnimal  um preço por animal — cada entrada
     *                                     cria um Animal novo (LAB-SA-012:
     *                                     nenhum outro atributo biológico
     *                                     entra no corte mínimo de Compra).
     */
    public function registrar(int $usuarioId, int $fazendaId, int $fornecedorId, array $valoresPorAnimal, string $dataCompra, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        // Gate de Decisão de Domínio (27/08/2026) — chave_idempotencia vazia
        // recusada, mesma correção simétrica em VendaService. Não é sobre
        // formato: é que uma string vazia nunca foi gerada de propósito por
        // nenhum caller real, mais provável de ser erro de integração.
        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = Compra::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'compra' => $existente];
        }

        if (empty($valoresPorAnimal)) {
            throw new DomainException('Uma Compra precisa de pelo menos um animal.');
        }

        // Gate de Decisão de Domínio (27/08/2026) — declaração direta do
        // produtor: uma aquisição sem pagamento não é o fato Compra, é um
        // fato diferente (Doação — MAPA-DOMINIO.md, conceito novo, fora de
        // escopo deste vertical). Preço <= 0 nunca é uma Compra válida.
        foreach ($valoresPorAnimal as $valor) {
            if ($valor <= 0) {
                throw new DomainException(
                    "Compra exige preço positivo por animal (recebido: {$valor}). Aquisição sem pagamento é um fato diferente (Doação), fora do corte mínimo deste vertical."
                );
            }
        }

        // Revisão de fronteira (27/08/2026) — achado real: sem esta checagem,
        // um fornecedor_id inexistente violava a FK em compras.fornecedor_id
        // dentro da transação, e violacaoDeUnicidade() confundia isso com
        // colisão de chave_idempotencia (as duas retornam SQLSTATE 23000) —
        // o chamador recebia ModelNotFoundException, não um erro que diz o
        // que realmente aconteceu. Checado explicitamente, antes da
        // transação, mesmo padrão de garantirRelacaoComFazenda().
        if (! Fornecedor::find($fornecedorId)) {
            throw new DomainException("Fornecedor #{$fornecedorId} não encontrado.");
        }

        try {
            return DB::transaction(function () use ($fazendaId, $fornecedorId, $valoresPorAnimal, $dataCompra, $chaveIdempotencia) {
                $valorTotal = round(array_sum($valoresPorAnimal), 2);

                [$deducaoFiscal, $ehPremissa] = $this->calcularDeducaoFiscal($fazendaId, $valorTotal);

                $compra = Compra::create([
                    'fazenda_id' => $fazendaId,
                    'fornecedor_id' => $fornecedorId,
                    'compra_original_id' => null,
                    'chave_idempotencia' => $chaveIdempotencia,
                    'data_compra' => $dataCompra,
                    'valor_total' => $valorTotal,
                    'deducao_fiscal' => $deducaoFiscal,
                    'fiscal_e_premissa' => $ehPremissa,
                ]);

                $animaisCriados = [];
                foreach ($valoresPorAnimal as $valor) {
                    // Lote nunca é automático (VERTICAL-COMPRA.md §8.1/§6) —
                    // todo animal sai da Compra sem lote_id, com seu próprio
                    // custo_aquisicao.
                    $animal = Animal::create([
                        'fazenda_id' => $fazendaId,
                        'lote_id' => null,
                        'custo_aquisicao' => $valor,
                        'status' => 'ativo',
                    ]);

                    CompraItem::create([
                        'compra_id' => $compra->id,
                        'animal_id' => $animal->id,
                        'valor' => $valor,
                    ]);

                    $animaisCriados[] = $animal;
                }

                $obrigacao = ObrigacaoFinanceira::create([
                    'fazenda_id' => $fazendaId,
                    'compra_id' => $compra->id,
                    'valor' => $valorTotal,
                    'status' => 'pago',
                ]);

                $this->registrarEvento('compra_concluida', $fazendaId, $chaveIdempotencia, [
                    'tipo' => 'compra_concluida', 'compra_id' => $compra->id, 'fazenda_id' => $fazendaId,
                ]);

                return [
                    'reenvio_detectado' => false,
                    'compra' => $compra,
                    'animais' => $animaisCriados,
                    'obrigacao_financeira' => $obrigacao,
                    'valor_total' => $valorTotal,
                    'deducao_fiscal' => $deducaoFiscal,
                    'fiscal_e_premissa' => $ehPremissa,
                ];
            });
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'compra' => Compra::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
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

    /**
     * VERTICAL-COMPRA.md §3b — dedução/crédito fiscal de entrada. Mais aberta
     * ainda que a de Venda: nenhum LAB declarou um valor de exemplo. Fica
     * sempre 0, marcada como premissa, até uma regra real ser declarada.
     */
    private function calcularDeducaoFiscal(int $fazendaId, float $valorTotal): array
    {
        return [0.0, true];
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
