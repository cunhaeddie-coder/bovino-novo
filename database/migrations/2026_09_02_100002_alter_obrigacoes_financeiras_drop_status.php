<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// INV-032 — status deixa de ser uma coluna gravada (nascia sempre 'pago',
// write-once, incompatível com dívida em aberto que Forma de Pagamento
// introduz) e vira accessor computado em ObrigacaoFinanceira, derivado só
// das suas formasPagamento(). Manter a coluna física ao lado de um accessor
// de mesmo nome seria a gambiarra que a Regra Zero proíbe — duas fontes de
// verdade, uma delas necessariamente desatualizada.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('obrigacoes_financeiras', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('obrigacoes_financeiras', function (Blueprint $table) {
            $table->string('status')->default('pago');
        });
    }
};
