<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VERTICAL-INTELIGENCIA-MERCADO.md §6 — sigla de 2 letras, nullable, sem
// backfill (Regra Zero). Formato confirmado por evidência real do Atual
// (IntelligenciaController::logBusca(), 'estado' => 'nullable|string|size:2').
// Decisão do produtor (14/09/2026): estado vive só aqui, nunca duplicado em
// Animal — um Animal sempre herda o estado da própria Fazenda.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fazendas', function (Blueprint $table) {
            $table->string('estado', 2)->nullable()->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('fazendas', function (Blueprint $table) {
            $table->dropColumn('estado');
        });
    }
};
