<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-FOLHA-PAGAMENTO.md §4 — folha_pagamento_id entra como 4o
// membro do grupo mutuamente exclusivo ja existente (compra_id/
// compra_insumo_id/venda_id), sempre com direcao=a_pagar. Guard de aplicacao
// em ObrigacaoFinanceira::booted() e estendido, nao reescrito.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('obrigacoes_financeiras', function (Blueprint $table) {
            $table->foreignId('folha_pagamento_id')->nullable()->after('venda_id')->constrained('folhas_pagamento');
        });
    }

    public function down(): void
    {
        Schema::table('obrigacoes_financeiras', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folha_pagamento_id');
        });
    }
};
