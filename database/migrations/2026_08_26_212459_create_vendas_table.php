<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-VENDA.md — venda original e correção são a MESMA tabela
// (§1), diferenciadas por venda_original_id (§3); toda a linha é imutável
// depois de criada (§5) — correção nunca é UPDATE, sempre uma nova linha (§6).
// chave_idempotencia é a identidade do fato no mundo (§2, INV-028) —
// corrigido em revisão adversarial (26/08/2026): UNIQUE por (fazenda_id,
// chave_idempotencia), nunca global. Uma chave gerada ingenuamente pelo
// cliente (contador local, ex: "venda-001") pode colidir entre Fazendas
// diferentes; UNIQUE global faria o dedup de uma vazar a Venda de outra —
// confirmado por execução real durante a revisão do Vertical Venda.
// animal_ids segue como JSON (igual ao Spike 006) — vínculo Venda↔Animal via
// pivot fica deliberadamente em aberto até um caso de uso real exigir consulta
// pelo lado do animal (§11, decisão não tomada por suposição).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('venda_original_id')->nullable()->constrained('vendas');
            $table->string('chave_idempotencia');
            $table->json('animal_ids');
            $table->decimal('valor_bruto', 14, 2);
            $table->decimal('cpv', 14, 2);
            $table->decimal('deducao_fiscal', 14, 2);
            $table->boolean('fiscal_e_premissa');
            $table->decimal('receita_liquida', 14, 2);
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendas');
    }
};
