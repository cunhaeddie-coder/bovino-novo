<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// GATE-DECISAO-DOMINIO-DATA-HORA.md — extensão confirmada pelo produtor
// (04/09/2026): publicado_em/encerrado_em também precisam de data e hora,
// mesma disciplina já aplicada a vendas/compras/compras_insumo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anuncios', function (Blueprint $table) {
            $table->dateTime('publicado_em')->change();
            $table->dateTime('encerrado_em')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('anuncios', function (Blueprint $table) {
            $table->date('publicado_em')->change();
            $table->date('encerrado_em')->nullable()->change();
        });
    }
};
