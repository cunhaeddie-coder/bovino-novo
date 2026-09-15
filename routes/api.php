<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

// SCHEMA-CONTRATO-AUTENTICACAO.md §5 — primeira rota HTTP real do projeto.
Route::post('/auth/registrar', [AuthController::class, 'registrar']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/eu', [AuthController::class, 'eu']);
});
