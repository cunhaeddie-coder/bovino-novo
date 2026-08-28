<?php

namespace App\Services;

use App\Models\CompraInsumo;
use App\Models\CompraInsumoItem;
use App\Models\EventoDominio;
use App\Models\Fornecedor;
use App\Models\Insumo;
use App\Models\ObrigacaoFinanceira;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * O núcleo do Vertical 3 (Compra de Insumo), nascido de
 * VERTICAL-COMPRA-INSUMO.md, traduzindo SCHEMA-CONTRATO-COMPRA-INSUMO.md
 * pra código real. Reaproveita o mesmo mecanismo genérico já provado (núcleo
 * atômico, idempotência, isolamento, outbox) — a diferença estrutural real
 * é que um item de Compra de Insumo pode referenciar um Insumo que já
 * existe (reposição) ou criar um novo, nunca por casamento automático de
 * nome (VERTICAL-COMPRA-INSUMO.md §8.1).
 */
class CompraInsumoService
{
    public function buscar(int $usuarioId, int $compraInsumoId): ?CompraInsumo
    {
        $compra = CompraInsumo::find($compraInsumoId);
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
     * @param  array<int, array{insumo_id?: int, insumo_novo?: array{nome: string}, quantidade: float, valor_unitario: float}>  $itens
     *                                                                                                                                  cada entrada referencia um Insumo já existente (insumo_id) OU declara um novo (insumo_novo) — nunca os dois, nunca nenhum
     *                                                                                                                                  (VERTICAL-COMPRA-INSUMO.md §8.1: sempre resolvido no momento do registro, nunca casamento automático de nome).
     */
    public function registrar(int $usuarioId, int $fazendaId, int $fornecedorId, array $itens, string $dataCompra, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        // GATE-DECISAO-DOMINIO-COMPRA.md — chave vazia recusada, mesma regra
        // geral de Compra (Animal e Insumo).
        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = CompraInsumo::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'compra' => $existente];
        }

        if (empty($itens)) {
            throw new DomainException('Uma Compra de Insumo precisa de pelo menos um item.');
        }

        // SCHEMA-CONTRATO-COMPRA-INSUMO.md §12 — quantidade/valor <= 0 nunca
        // é Compra. Valor zero é um fato diferente (Amostra Grátis,
        // MAPA-DOMINIO.md), fora do corte mínimo deste vertical
        // (PRINCIPIOS-BOVINO-NOVO.md item 12).
        foreach ($itens as $item) {
            $quantidade = $item['quantidade'] ?? null;
            $valorUnitario = $item['valor_unitario'] ?? null;
            if ($quantidade === null || $quantidade <= 0) {
                throw new DomainException("Compra de Insumo exige quantidade positiva por item (recebido: {$quantidade}).");
            }
            if ($valorUnitario === null || $valorUnitario <= 0) {
                throw new DomainException(
                    "Compra de Insumo exige valor unitário positivo por item (recebido: {$valorUnitario}). Insumo recebido sem pagamento é um fato diferente (Amostra Grátis), fora do corte mínimo deste vertical."
                );
            }
            if (! isset($item['insumo_id']) && ! isset($item['insumo_novo'])) {
                throw new DomainException('Todo item precisa referenciar um Insumo existente (insumo_id) ou declarar um novo (insumo_novo).');
            }
            if (isset($item['insumo_id']) && isset($item['insumo_novo'])) {
                throw new DomainException('Um item não pode referenciar um Insumo existente e declarar um novo ao mesmo tempo.');
            }
        }

        // Revisão de fronteira de Compra de Animal (mesma classe de bug já
        // corrigida): fornecedor_id/insumo_id inexistentes/de outra Fazenda
        // violam FK/isolamento dentro da transação, e violacaoDeUnicidade()
        // confundiria isso com colisão de chave_idempotencia (mesma
        // SQLSTATE 23000). Checado explicitamente, antes da transação.
        if (! Fornecedor::find($fornecedorId)) {
            throw new DomainException("Fornecedor #{$fornecedorId} não encontrado.");
        }
        foreach ($itens as $item) {
            if (isset($item['insumo_id']) && ! Insumo::where('id', $item['insumo_id'])->where('fazenda_id', $fazendaId)->exists()) {
                throw new DomainException("Insumo #{$item['insumo_id']} não encontrado nesta Fazenda.");
            }
        }

        // Achado real, mesma classe do bug de Fornecedor: um insumo_novo
        // com nome já existente na Fazenda (ou repetido entre itens da
        // mesma Compra) violava UNIQUE(fazenda_id, nome) DENTRO da
        // transação, e violacaoDeUnicidade() confundia isso com colisão de
        // chave_idempotencia — como a CompraInsumo nunca chegava a ser
        // commitada, o chamador recebia ModelNotFoundException, não um erro
        // que diz o que realmente aconteceu. Confirmado falhando antes da
        // correção (execução real via probe), corrigido com checagem
        // explícita, mesmo padrão de garantirRelacaoComFazenda().
        $nomesNovosNestaCompra = [];
        foreach ($itens as $item) {
            if (! isset($item['insumo_novo'])) {
                continue;
            }
            $nome = $item['insumo_novo']['nome'];
            if (in_array($nome, $nomesNovosNestaCompra, true)) {
                throw new DomainException("Insumo \"{$nome}\" declarado como novo mais de uma vez na mesma Compra.");
            }
            $nomesNovosNestaCompra[] = $nome;
            if (Insumo::where('fazenda_id', $fazendaId)->where('nome', $nome)->exists()) {
                throw new DomainException("Insumo \"{$nome}\" já existe nesta Fazenda — referencie por insumo_id, não declare como novo.");
            }
        }

        // Mesma classe de bug, outra ponta: dois itens da mesma Compra
        // referenciando o mesmo insumo_id existente violaria
        // UNIQUE(compra_insumo_id, insumo_id) dentro da transação, com a
        // mesma confusão de SQLSTATE 23000. Um item por Insumo — quem
        // compra a mesma coisa duas vezes soma quantidade numa única linha,
        // nunca duas.
        $idsExistentesNestaCompra = [];
        foreach ($itens as $item) {
            if (! isset($item['insumo_id'])) {
                continue;
            }
            if (in_array($item['insumo_id'], $idsExistentesNestaCompra, true)) {
                throw new DomainException("Insumo #{$item['insumo_id']} referenciado mais de uma vez na mesma Compra — some a quantidade numa única linha.");
            }
            $idsExistentesNestaCompra[] = $item['insumo_id'];
        }

        try {
            return DB::transaction(function () use ($fazendaId, $fornecedorId, $itens, $dataCompra, $chaveIdempotencia) {
                $valorTotal = round(array_sum(array_map(
                    fn (array $item) => $item['quantidade'] * $item['valor_unitario'],
                    $itens
                )), 2);

                [$deducaoFiscal, $ehPremissa] = $this->calcularDeducaoFiscal($fazendaId, $valorTotal);

                $compra = CompraInsumo::create([
                    'fazenda_id' => $fazendaId,
                    'fornecedor_id' => $fornecedorId,
                    'compra_original_id' => null,
                    'chave_idempotencia' => $chaveIdempotencia,
                    'data_compra' => $dataCompra,
                    'valor_total' => $valorTotal,
                    'deducao_fiscal' => $deducaoFiscal,
                    'fiscal_e_premissa' => $ehPremissa,
                ]);

                $insumosAfetados = [];
                foreach ($itens as $item) {
                    if (isset($item['insumo_novo'])) {
                        $insumo = Insumo::create([
                            'fazenda_id' => $fazendaId,
                            'nome' => $item['insumo_novo']['nome'],
                            'quantidade' => 0,
                            'valor_referencia' => 0,
                        ]);
                    } else {
                        $insumo = Insumo::where('id', $item['insumo_id'])->where('fazenda_id', $fazendaId)->firstOrFail();
                    }

                    // SCHEMA-CONTRATO-COMPRA-INSUMO.md §5 — núcleo obrigatório:
                    // quantidade soma, valor_referencia é o preço mais
                    // recente pago (nunca média, nunca acumulado).
                    $insumo->quantidade += $item['quantidade'];
                    $insumo->valor_referencia = $item['valor_unitario'];
                    $insumo->save();

                    CompraInsumoItem::create([
                        'compra_insumo_id' => $compra->id,
                        'insumo_id' => $insumo->id,
                        'quantidade' => $item['quantidade'],
                        'valor_unitario' => $item['valor_unitario'],
                    ]);

                    $insumosAfetados[] = $insumo;
                }

                $obrigacao = ObrigacaoFinanceira::create([
                    'fazenda_id' => $fazendaId,
                    'compra_id' => null,
                    'compra_insumo_id' => $compra->id,
                    'valor' => $valorTotal,
                    'status' => 'pago',
                ]);

                $this->registrarEvento('compra_insumo_concluida', $fazendaId, $chaveIdempotencia, [
                    'tipo' => 'compra_insumo_concluida', 'compra_insumo_id' => $compra->id, 'fazenda_id' => $fazendaId,
                ]);

                return [
                    'reenvio_detectado' => false,
                    'compra' => $compra,
                    'insumos' => $insumosAfetados,
                    'obrigacao_financeira' => $obrigacao,
                    'valor_total' => $valorTotal,
                    'deducao_fiscal' => $deducaoFiscal,
                    'fiscal_e_premissa' => $ehPremissa,
                ];
            });
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'compra' => CompraInsumo::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
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
     * SCHEMA-CONTRATO-COMPRA-INSUMO.md §3b — mesma premissa pendente de
     * Compra de Animal, confirmado que não mudou. Fica sempre 0, marcada
     * como premissa, até uma regra real ser declarada.
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
