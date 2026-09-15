<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-ACERTO-RESCISAO.md §5 - item_acerto_rescisao_id entra como
// 7o membro do grupo mutuamente exclusivo ja existente (compra_id/
// compra_insumo_id/venda_id/folha_pagamento_id/parcela_arrendamento_id/
// ordem_frete_id), sempre com direcao=a_pagar. Guard de aplicacao em
// ObrigacaoFinanceira::booted() e estendido, nao reescrito.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('obrigacoes_financeiras', function (Blueprint $table) {
            $table->foreignId('item_acerto_rescisao_id')->nullable()->after('ordem_frete_id')->constrained('itens_acerto_rescisao');
        });
    }

    public function down(): void
    {
        Schema::table('obrigacoes_financeiras', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_acerto_rescisao_id');
        });
    }
};
