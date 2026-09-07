<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-FOLHA-PAGAMENTO.md §1/§2 — FolhaPagamento e o Fato "a
// obrigacao de pagar o salario de um Funcionario num mes de referencia".
// UNIQUE(funcionario_id, mes_referencia) garante 1 folha por funcionario por
// mes por desenho de schema, sem precisar de chave_idempotencia propria (a
// chave natural ja existe). Sem obrigacao_financeira_id aqui de proposito
// (evitaria referencia circular com obrigacoes_financeiras.folha_pagamento_id)
// -- FolhaPagamento e criada primeiro, ObrigacaoFinanceira referencia ela
// depois (mesmo sentido de Gta.venda_id/SeparacaoVenda.venda_id, nunca o
// inverso).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folhas_pagamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('funcionario_id')->constrained('funcionarios');
            $table->string('mes_referencia');
            $table->decimal('valor', 14, 2);
            $table->dateTime('data_geracao');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['funcionario_id', 'mes_referencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folhas_pagamento');
    }
};
