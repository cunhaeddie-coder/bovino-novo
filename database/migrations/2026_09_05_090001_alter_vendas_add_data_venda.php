<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// GATE-DECISAO-DOMINIO-DATA-HORA.md — decisão direta do produtor (04/09/2026):
// venda e confirmação da venda precisam constar com DATA E HORA, motivado por
// auditoria eficaz e por uma eventual Mediação Online de Conflitos pelo
// Mercado (Marketplace). `vendas` nunca teve nenhum campo declarável de
// data/hora do fato — só `created_at` (prova de sistema, não substitui o
// momento real declarado). Nullable no schema (mesmo padrão de venda_id/
// direcao em obrigacoes_financeiras — Princípio 4b, ALTER TABLE não impõe
// NOT NULL sem backfill); obrigatoriedade garantida por guard de aplicação
// em Venda::booted().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendas', function (Blueprint $table) {
            $table->dateTime('data_venda')->nullable()->after('venda_original_id');
        });
    }

    public function down(): void
    {
        Schema::table('vendas', function (Blueprint $table) {
            $table->dropColumn('data_venda');
        });
    }
};
