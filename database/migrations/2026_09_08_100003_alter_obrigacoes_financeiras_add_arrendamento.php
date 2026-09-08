<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-ARRENDAMENTO.md §5 - achado corrigido antes do codigo:
// a FK precisa apontar pra ParcelaArrendamento (a parcela especifica que
// gerou ESTA obrigacao), nao pro Arrendamento (o contrato inteiro pode ter
// N parcelas, cada uma com sua propria ObrigacaoFinanceira - apontar pro
// contrato tornaria impossivel saber a qual parcela cada obrigacao
// pertence). parcela_arrendamento_id e o 5o membro do grupo mutuamente
// exclusivo (compra_id/compra_insumo_id/venda_id/folha_pagamento_id).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('obrigacoes_financeiras', function (Blueprint $table) {
            $table->foreignId('parcela_arrendamento_id')->nullable()->after('folha_pagamento_id')->constrained('parcelas_arrendamento');
        });
    }

    public function down(): void
    {
        Schema::table('obrigacoes_financeiras', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parcela_arrendamento_id');
        });
    }
};
