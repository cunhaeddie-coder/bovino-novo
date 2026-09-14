<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-FRETE-LOGISTICA.md §1/§6 - flag minima pra aprovar
// cadastro de Motorista e editar a comissao global da plataforma.
// Referencia conceitual ao acesso que ja existe em admin.bovino.agr.br
// (Bovino Atual) - sem grade de niveis, sem tela nova.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('eh_administrador')->default(false)->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('eh_administrador');
        });
    }
};
