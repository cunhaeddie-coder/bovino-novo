<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-ARRENDAMENTO.md §1/§2 - Arrendamento e o Fato "um contrato
// de uso de pasto de terceiro, com valor total, periodicidade e prazo". O
// arrendador reaproveita fornecedores, sem alteracao de schema. Numero total
// de parcelas nunca e gravado - calculado a partir de data_inicio/data_fim/
// periodicidade (corrige o achado real do LAB-FA-020).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arrendamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('fornecedor_id')->constrained('fornecedores');
            $table->decimal('valor_total', 14, 2);
            $table->string('periodicidade');
            $table->dateTime('data_inicio');
            $table->dateTime('data_fim');
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arrendamentos');
    }
};
