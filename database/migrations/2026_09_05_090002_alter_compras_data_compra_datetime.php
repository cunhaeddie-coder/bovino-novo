<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// GATE-DECISAO-DOMINIO-DATA-HORA.md — decisão direta do produtor (04/09/2026):
// compra e confirmação da compra precisam constar com DATA E HORA. compras.data_compra
// já existia, mas só como `date` (granularidade de dia) — vira `datetime`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->dateTime('data_compra')->change();
        });
    }

    public function down(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->date('data_compra')->change();
        });
    }
};
