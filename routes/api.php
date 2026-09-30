<?php

use App\Http\Controllers\Api\AnimalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompraController;
use App\Http\Controllers\Api\CompraInsumoController;
use App\Http\Controllers\Api\ConfirmacaoPrenhezController;
use App\Http\Controllers\Api\EventoSaudeController;
use App\Http\Controllers\Api\FormaPagamentoController;
use App\Http\Controllers\Api\FornecedorController;
use App\Http\Controllers\Api\GtaController;
use App\Http\Controllers\Api\InsumoController;
use App\Http\Controllers\Api\LoteController;
use App\Http\Controllers\Api\MarcacaoCioController;
use App\Http\Controllers\Api\ProducaoLeiteiraController;
use App\Http\Controllers\Api\ProtocoloReprodutivoController;
use App\Http\Controllers\Api\VendaController;
use Illuminate\Support\Facades\Route;

// SCHEMA-CONTRATO-AUTENTICACAO.md §5 — primeira rota HTTP real do projeto.
Route::post('/auth/registrar', [AuthController::class, 'registrar']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/eu', [AuthController::class, 'eu']);

    // Fatia vertical de frontend (Decisão #10 reaberta 15/09/2026) — expõe
    // exatamente os 4 verticais já aprovados em EXCECAO-MATRIZ-FASE3.md.
    // Leitura de apoio (nunca escreve, isolamento por Fazenda igual a todo
    // Service de domínio):
    Route::get('/animais', [AnimalController::class, 'index']);
    Route::get('/fornecedores', [FornecedorController::class, 'index']);
    Route::get('/insumos', [InsumoController::class, 'index']);

    // Módulo Rebanho (wireframe "Navegação Completa" aprovado 16/09/2026)
    // — mesma disciplina de thin wrapper, leitura de apoio nunca escreve.
    Route::get('/lotes', [LoteController::class, 'index']);

    Route::get('/gtas', [GtaController::class, 'index']);
    Route::post('/gtas', [GtaController::class, 'store']);
    Route::post('/gtas/{gta}/concluir', [GtaController::class, 'concluir']);

    Route::get('/eventos-saude', [EventoSaudeController::class, 'index']);
    Route::post('/eventos-saude', [EventoSaudeController::class, 'store']);

    Route::get('/protocolos-reprodutivos', [ProtocoloReprodutivoController::class, 'index']);
    Route::post('/protocolos-reprodutivos', [ProtocoloReprodutivoController::class, 'store']);
    Route::post('/protocolos-reprodutivos/{protocolo}/etapas', [ProtocoloReprodutivoController::class, 'cumprirEtapa']);

    Route::get('/marcacoes-cio', [MarcacaoCioController::class, 'index']);
    Route::post('/marcacoes-cio', [MarcacaoCioController::class, 'store']);

    Route::get('/confirmacoes-prenhez', [ConfirmacaoPrenhezController::class, 'index']);
    Route::post('/confirmacoes-prenhez', [ConfirmacaoPrenhezController::class, 'store']);

    Route::get('/producoes-leiteiras', [ProducaoLeiteiraController::class, 'index']);
    Route::post('/producoes-leiteiras', [ProducaoLeiteiraController::class, 'store']);

    Route::get('/vendas', [VendaController::class, 'index']);
    Route::post('/vendas', [VendaController::class, 'store']);
    Route::post('/vendas/{venda}/corrigir', [VendaController::class, 'corrigir']);

    Route::get('/compras', [CompraController::class, 'index']);
    Route::post('/compras', [CompraController::class, 'store']);

    Route::get('/compras-insumo', [CompraInsumoController::class, 'index']);
    Route::post('/compras-insumo', [CompraInsumoController::class, 'store']);

    Route::get('/formas-pagamento', [FormaPagamentoController::class, 'index']);
    Route::post('/formas-pagamento/{formaPagamento}/liquidar', [FormaPagamentoController::class, 'liquidar']);
    Route::put('/formas-pagamento/{formaPagamento}', [FormaPagamentoController::class, 'update']);
});
