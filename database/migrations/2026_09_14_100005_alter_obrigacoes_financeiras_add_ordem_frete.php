<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-FRETE-LOGISTICA.md §1/§3 - ordem_frete_id e o 6o membro
// do grupo mutuamente exclusivo (compra_id/compra_insumo_id/venda_id/
// folha_pagamento_id/parcela_arrendamento_id), sempre com
// direcao='a_pagar' (a Fazenda deve ao Motorista) - mesmo padrao
// generalizado ja usado nas 4 extensoes anteriores desse grupo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('obrigacoes_financeiras', function (Blueprint $table) {
            $table->foreignId('ordem_frete_id')->nullable()->after('parcela_arrendamento_id')->constrained('ordens_frete');
        });
    }

    public function down(): void
    {
        Schema::table('obrigacoes_financeiras', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ordem_frete_id');
        });
    }
};
