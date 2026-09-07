<?php

namespace App\Services;

use App\Models\EventoDominio;
use App\Models\Funcionario;
use App\Models\Usuario;
use DomainException;
use Illuminate\Database\QueryException;

/**
 * O núcleo do cadastro do Vertical 10 (Folha de Pagamento), nascido de
 * VERTICAL-FOLHA-PAGAMENTO.md, traduzindo SCHEMA-CONTRATO-FOLHA-PAGAMENTO.md
 * pra código real. Funcionario é entidade mutável (cadastro com lifecycle),
 * não um evento imutável — sem lockForUpdate() (sem disputa de recurso
 * compartilhado, mesma categoria de risco de NascimentoService).
 */
class FuncionarioService
{
    public function buscar(int $usuarioId, int $funcionarioId): ?Funcionario
    {
        $funcionario = Funcionario::find($funcionarioId);
        if (! $funcionario) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($funcionario->fazenda_id)) {
            return null;
        }

        return $funcionario;
    }

    public function contratar(int $usuarioId, int $fazendaId, string $nome, ?string $cargo, float $salario, string $dataContratacao, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = Funcionario::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'funcionario' => $existente];
        }

        if (trim($nome) === '') {
            throw new DomainException('Funcionario exige nome.');
        }

        try {
            $funcionario = Funcionario::create([
                'fazenda_id' => $fazendaId,
                'nome' => $nome,
                'cargo' => $cargo,
                'salario' => $salario,
                'status' => 'ativo',
                'data_contratacao' => $dataContratacao,
                'chave_idempotencia' => $chaveIdempotencia,
            ]);

            $this->registrarEvento('funcionario_contratado', $fazendaId, $chaveIdempotencia, [
                'tipo' => 'funcionario_contratado', 'funcionario_id' => $funcionario->id,
            ]);

            return ['reenvio_detectado' => false, 'funcionario' => $funcionario];
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'funcionario' => Funcionario::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
            }
            throw $e;
        }
    }

    public function desligar(int $usuarioId, int $funcionarioId, string $dataDesligamento): Funcionario
    {
        $funcionario = Funcionario::find($funcionarioId);
        if (! $funcionario) {
            throw new DomainException("Funcionario #{$funcionarioId} não encontrado.");
        }

        $this->garantirRelacaoComFazenda($usuarioId, $funcionario->fazenda_id);

        if ($funcionario->status === 'desligado') {
            return $funcionario;
        }

        $funcionario->update(['status' => 'desligado', 'data_desligamento' => $dataDesligamento]);

        $this->registrarEvento('funcionario_desligado', $funcionario->fazenda_id, $funcionario->chave_idempotencia.':desligamento', [
            'tipo' => 'funcionario_desligado', 'funcionario_id' => $funcionario->id,
        ]);

        return $funcionario->fresh();
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
