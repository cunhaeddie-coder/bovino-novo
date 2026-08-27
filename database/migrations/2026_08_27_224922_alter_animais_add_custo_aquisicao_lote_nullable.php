<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-COMPRA.md §5/§6 — Lote nunca é automático (decisão do
// produtor). Dentro do corte mínimo de Compra, todo animal sai com
// lote_id=NULL e custo_aquisicao preenchido com o preço individual
// declarado. custo_aquisicao e lote_id são mutuamente exclusivos: nunca os
// dois preenchidos, nunca os dois nulos — quando um animal tem Lote, o custo
// vive agregado em lotes.custo_aquisicao (como já era), nunca duplicado aqui.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animais', function (Blueprint $table) {
            $table->foreignId('lote_id')->nullable()->change();
            $table->decimal('custo_aquisicao', 14, 2)->nullable()->after('lote_id');
        });
    }

    public function down(): void
    {
        Schema::table('animais', function (Blueprint $table) {
            $table->dropColumn('custo_aquisicao');
            $table->foreignId('lote_id')->nullable(false)->change();
        });
    }
};
