<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\ConfirmacaoPrenhez;
use App\Models\EventoDominio;
use App\Models\Usuario;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\QueryException;

/**
 * O núcleo do Vertical 17 (Marcação de Cio + Confirmação de Prenhez —
 * metade Confirmação de Prenhez). Mesma forma exata de MarcacaoCioService —
 * fato separado, decisão do produtor (VERTICAL-MARCACAO-CIO-PRENHEZ.md §2).
 * INSERT puro, sem lockForUpdate() — hipótese a confirmar no spike.
 */
class ConfirmacaoPrenhezService
{
    private const DIAS_GESTACAO_BOVINA = 283;

    public function buscar(int $usuarioId, int $confirmacaoId): ?ConfirmacaoPrenhez
    {
        $confirmacao = ConfirmacaoPrenhez::find($confirmacaoId);
        if (! $confirmacao) {
            return null;
        }

        $usuario = Usuario::findOrFail($usuarioId);
        if (! $usuario->temRelacaoComFazenda($confirmacao->fazenda_id)) {
            return null;
        }

        return $confirmacao;
    }

    public function registrar(int $usuarioId, int $fazendaId, int $vacaId, string $resultado, string $tipoExame, string $dataConfirmacao, string $chaveIdempotencia): array
    {
        $this->garantirRelacaoComFazenda($usuarioId, $fazendaId);

        if (trim($chaveIdempotencia) === '') {
            throw new DomainException('chave_idempotencia não pode ser vazia.');
        }

        if ($existente = ConfirmacaoPrenhez::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->first()) {
            return ['reenvio_detectado' => true, 'confirmacao' => $existente];
        }

        if (! Animal::where('fazenda_id', $fazendaId)->where('id', $vacaId)->exists()) {
            throw new DomainException("Vaca #{$vacaId} não encontrada ou não pertence a esta Fazenda.");
        }

        if (! in_array($resultado, ['positivo', 'negativo'], true)) {
            throw new DomainException("resultado precisa ser 'positivo' ou 'negativo' (recebido: {$resultado}).");
        }

        if (trim($tipoExame) === '') {
            throw new DomainException('tipo_exame não pode ser vazio.');
        }

        // SCHEMA-CONTRATO-MARCACAO-CIO-PRENHEZ.md §3 — só existe data de
        // parto estimada quando o resultado é positivo; a duração de
        // gestação bovina padrão (283 dias) é fato zootécnico, não decisão
        // de produto.
        $dataPartoEstimada = $resultado === 'positivo'
            ? Carbon::parse($dataConfirmacao)->addDays(self::DIAS_GESTACAO_BOVINA)->toDateString()
            : null;

        try {
            $confirmacao = ConfirmacaoPrenhez::create([
                'fazenda_id' => $fazendaId,
                'vaca_id' => $vacaId,
                'resultado' => $resultado,
                'tipo_exame' => $tipoExame,
                'data_confirmacao' => $dataConfirmacao,
                'data_parto_estimada' => $dataPartoEstimada,
                'chave_idempotencia' => $chaveIdempotencia,
            ]);

            $this->registrarEvento('confirmacao_prenhez_registrada', $fazendaId, $chaveIdempotencia, [
                'tipo' => 'confirmacao_prenhez_registrada', 'confirmacao_id' => $confirmacao->id,
                'vaca_id' => $vacaId, 'resultado' => $resultado, 'data_parto_estimada' => $dataPartoEstimada,
            ]);

            return ['reenvio_detectado' => false, 'confirmacao' => $confirmacao];
        } catch (QueryException $e) {
            if ($this->violacaoDeUnicidade($e)) {
                return ['reenvio_detectado' => true, 'confirmacao' => ConfirmacaoPrenhez::where('fazenda_id', $fazendaId)->where('chave_idempotencia', $chaveIdempotencia)->firstOrFail()];
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
