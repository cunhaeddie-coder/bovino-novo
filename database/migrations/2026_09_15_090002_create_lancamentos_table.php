<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-CARTEIRA.md §1/§2 - uma entrada/saida numa Conta.
// forma_pagamento_id so preenchido quando origem=automatico (INV-056,
// guard de aplicacao em Lancamento::booted()). chave_idempotencia sempre
// preenchida - manual (digitada) ou automatica ("forma-pagamento-{id}",
// derivada do proprio evento que a originou).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lancamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas');
            $table->string('tipo');
            $table->decimal('valor', 14, 2);
            $table->dateTime('data_lancamento');
            $table->string('descricao');
            $table->string('origem');
            $table->foreignId('forma_pagamento_id')->nullable()->constrained('formas_pagamento');
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['conta_id', 'id']);
            $table->unique(['conta_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lancamentos');
    }
};
