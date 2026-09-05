<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// GATE-DECISAO-DOMINIO-DATA-HORA.md — mesma correção de compras.data_compra,
// aplicada a compras_insumo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras_insumo', function (Blueprint $table) {
            $table->dateTime('data_compra')->change();
        });
    }

    public function down(): void
    {
        Schema::table('compras_insumo', function (Blueprint $table) {
            $table->date('data_compra')->change();
        });
    }
};
