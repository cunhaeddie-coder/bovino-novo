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
            // Nome explícito e curto: o nome padrão do Laravel pra este par
            // de colunas passa de 64 caracteres (limite de identificador do
            // MySQL, SQLSTATE 42000/1059) — nunca detectado pela suíte de
            // testes porque roda em SQLite, que não impõe esse limite
            // (achado real, Spike 007 extensão Vertical 26, contra MySQL
            // real).
            $table->unique(['fazenda_origem_id', 'chave_idempotencia'], 'transferencias_fazenda_origem_chave_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transferencias_fazenda');
    }
};
