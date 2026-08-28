<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-COMPRA-INSUMO.md §1 — tabela própria, não reaproveita
// `compras`: mesmo padrão de isolamento já usado entre `vendas`/`compras`
// (fatos diferentes, tabelas diferentes), evita alterar um vertical fechado
// e testado (Compra de Animal) só pra acomodar este. compra_original_id
// nasce como placeholder estrutural (§6) — correção fora do corte mínimo,
// mesmo padrão de `compras.compra_original_id`. chave_idempotencia UNIQUE
// por (fazenda_id, chave) desde a primeira versão (§9), mesma lição já
// aplicada em Compra de Animal. valor_total é derivado (§5), nunca input
// direto.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras_insumo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('fornecedor_id')->constrained('fornecedores');
            $table->foreignId('compra_original_id')->nullable()->constrained('compras_insumo');
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
        Schema::dropIfExists('compras_insumo');
    }
};
