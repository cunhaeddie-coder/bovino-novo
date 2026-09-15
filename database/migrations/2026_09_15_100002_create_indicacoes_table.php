<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-PARCEIROS-COMISSAO.md §1/§2 - um cliente indicado por um
// Parceiro. cliente_documento nullable (texto livre - sem onboarding de
// produtor no Bovino Novo ainda). confirmada_em nullable - null ate um
// administrador confirmar (INV-058, mesmo padrao de FormaPagamento.pago_em).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parceiro_id')->constrained('parceiros');
            $table->string('cliente_nome');
            $table->string('cliente_documento')->nullable();
            $table->dateTime('data_indicacao');
            $table->string('chave_idempotencia');
            $table->dateTime('confirmada_em')->nullable();
            $table->timestamps();

            $table->index(['parceiro_id', 'id']);
            $table->unique(['parceiro_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicacoes');
    }
};
