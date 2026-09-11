<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-MARCACAO-CIO-PRENHEZ.md §1/§2/§3 - resultado de exame de
// confirmacao de prenhez numa vaca. data_parto_estimada nullable - so
// calculada quando resultado=positivo (283 dias de gestacao bovina padrao).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('confirmacoes_prenhez', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('vaca_id')->constrained('animais');
            $table->string('resultado');
            $table->string('tipo_exame');
            $table->dateTime('data_confirmacao');
            $table->date('data_parto_estimada')->nullable();
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confirmacoes_prenhez');
    }
};
