<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-COMPRA-INSUMO.md §7 — obrigacoes_financeiras cumpre o
// propósito com que foi desenhada (SCHEMA-CONTRATO-COMPRA.md §1: "genérica
// o suficiente pra não ser recriada por vertical"). compra_id passa a
// nullable, par com o novo compra_insumo_id — mutuamente exclusivos, mesmo
// padrão de animais.lote_id/custo_aquisicao, guard de aplicação em
// ObrigacaoFinanceira::booted(), não CHECK de banco (Princípio 4b).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('obrigacoes_financeiras', function (Blueprint $table) {
            $table->foreignId('compra_id')->nullable()->change();
            $table->foreignId('compra_insumo_id')->nullable()->after('compra_id')->constrained('compras_insumo');
        });
    }

    public function down(): void
    {
        Schema::table('obrigacoes_financeiras', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compra_insumo_id');
            $table->foreignId('compra_id')->nullable(false)->change();
        });
    }
};
