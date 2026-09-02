<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-FORMA-PAGAMENTO.md §1/§2 — o Fato de Domínio "como uma
// parte do valor de uma Compra/Venda será quitada", plural por operação.
// Sem fazenda_id próprio (§8, decisão explícita: "nunca um campo próprio,
// nunca inferido de outra forma") — a Fazenda é sempre a da operação que
// originou a Obrigação Financeira, alcançada via obrigacao_financeira_id.
// unidade/valor: duas dimensões independentes (§4) — em que unidade o valor
// está declarado (dinheiro fixo ou quantidade de arrobas) é sempre distinto
// de como ela é efetivamente quitada (meio_liquidacao, só existe depois de
// pago_em preenchido). vencimento NOT NULL sem exceção (INV-033, decisão
// categórica do produtor — mesmo à vista).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formas_pagamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obrigacao_financeira_id')->constrained('obrigacoes_financeiras');
            $table->string('nome');
            $table->string('unidade');
            $table->decimal('valor', 14, 2);
            $table->date('data');
            $table->date('vencimento');
            $table->date('pago_em')->nullable();
            $table->string('meio_liquidacao')->nullable();
            $table->decimal('cotacao_arroba_na_liquidacao', 14, 2)->nullable();
            $table->decimal('valor_liquidado_reais', 14, 2)->nullable();
            $table->foreignId('animal_id')->nullable()->constrained('animais');
            $table->timestamps();

            $table->index(['obrigacao_financeira_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formas_pagamento');
    }
};
