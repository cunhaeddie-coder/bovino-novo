<?php

use App\Http\Controllers\Api\AnimalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompraController;
use App\Http\Controllers\Api\CompraInsumoController;
use App\Http\Controllers\Api\FormaPagamentoController;
use App\Http\Controllers\Api\FornecedorController;
use App\Http\Controllers\Api\InsumoController;
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
