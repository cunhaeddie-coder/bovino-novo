<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Decisao do produtor (28/10/2026, V2 - VERTICAL-VENDA.md §12): uma versao
// de venda tem no maximo uma correcao; a proxima corrige a correcao
// (encadeia), nunca a mesma versao de novo. O guard de aplicacao recusa
// antes; o UNIQUE e a ultima barreira contra duas correcoes simultaneas
// (MySQL e SQLite aceitam varios NULL num UNIQUE, entao as vendas
// originais continuam livres).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendas', function (Blueprint $table) {
            $table->unique('venda_original_id');
        });
    }

    public function down(): void
    {
        Schema::table('vendas', function (Blueprint $table) {
            $table->dropUnique(['venda_original_id']);
        });
    }
};
