<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-FORMA-PAGAMENTO.md §3 — Opção A, decisão do produtor
// (02/09/2026): obrigacoes_financeiras cumpre de novo o propósito com que foi
// desenhada, agora também para Venda (assimetria pré-existente nunca
// percebida antes desta frente: Venda nunca gerou nenhuma Obrigação
// Financeira). venda_id entra como terceiro membro do grupo mutuamente
// exclusivo com compra_id/compra_insumo_id — guard de aplicação em
// ObrigacaoFinanceira::booted(), não CHECK de banco (Princípio 4b). direcao
// discrimina o sentido (a_pagar/a_receber) — sempre correlacionado com qual
// FK está preenchida, também garantido pelo guard.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('obrigacoes_financeiras', function (Blueprint $table) {
            $table->foreignId('venda_id')->nullable()->after('compra_insumo_id')->constrained('vendas');
            // Nullable no schema (mesmo padrão de compra_id/compra_insumo_id) —
            // obrigatoriedade e correlação com a FK preenchida são garantidas
            // pelo guard de aplicação em ObrigacaoFinanceira::booted(), nunca
            // por CHECK/NOT NULL de banco (Princípio 4b — ALTER TABLE não
            // consegue impor NOT NULL numa tabela já populada sem backfill,
            // e este projeto não tem dado real pra backfillar).
            $table->string('direcao')->nullable()->after('venda_id');
        });
    }

    public function down(): void
    {
        Schema::table('obrigacoes_financeiras', function (Blueprint $table) {
            $table->dropConstrainedForeignId('venda_id');
            $table->dropColumn('direcao');
        });
    }
};
