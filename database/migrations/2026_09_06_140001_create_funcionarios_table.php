<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-FOLHA-PAGAMENTO.md §1/§2 — Funcionario e entidade mutavel
// com lifecycle (contratacao -> alteracoes -> desligamento), mesmo
// tratamento de Fornecedor/Insumo/Lote, nao um evento imutavel.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funcionarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->string('nome');
            $table->string('cargo')->nullable();
            $table->decimal('salario', 14, 2);
            $table->string('status')->default('ativo');
            $table->dateTime('data_contratacao');
            $table->dateTime('data_desligamento')->nullable();
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->index(['fazenda_id', 'status']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funcionarios');
    }
};
