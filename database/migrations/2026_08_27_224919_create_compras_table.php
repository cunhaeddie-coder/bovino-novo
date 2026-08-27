<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-COMPRA.md — compra original e correção na mesma tabela
// (§4), diferenciadas por compra_original_id, mesmo padrão de `vendas`.
// chave_idempotencia UNIQUE por (fazenda_id, chave) desde a primeira versão
// (§9) — lição do achado #1 da revisão adversarial de Venda, aplicada de
// início, não corrigida depois. valor_total é derivado, nunca input direto
// (§12) — soma de compra_itens.valor.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('fornecedor_id')->constrained('fornecedores');
            $table->foreignId('compra_original_id')->nullable()->constrained('compras');
            $table->string('chave_idempotencia');
            $table->date('data_compra');
            $table->decimal('valor_total', 14, 2);
            $table->decimal('deducao_fiscal', 14, 2)->default(0);
            $table->boolean('fiscal_e_premissa')->default(true);
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras');
    }
};
