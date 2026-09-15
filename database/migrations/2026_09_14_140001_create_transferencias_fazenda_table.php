<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-TRANSFERENCIA-FAZENDA.md §1/§2 - o fato "N animais
// sairam da Fazenda A e entraram na Fazenda B", so entre Fazendas do
// mesmo Titular (INV-050). Sem coluna de titular_id aqui - checado em
// tempo real contra fazendas.titular_id, nunca duplicado.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transferencias_fazenda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_origem_id')->constrained('fazendas');
            $table->foreignId('fazenda_destino_id')->constrained('fazendas');
            $table->string('chave_idempotencia');
            $table->dateTime('data_transferencia');
            $table->timestamps();

            $table->index(['fazenda_origem_id', 'id']);
            $table->unique(['fazenda_origem_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transferencias_fazenda');
    }
};
