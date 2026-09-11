<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-MARCACAO-CIO-PRENHEZ.md §1/§2 - o Fato de Dominio "um
// Rufiao montou uma vaca, numa data". Sem UNIQUE(vaca_id, data) - uma vaca
// pode ser montada mais de uma vez ao longo do ciclo. Dedup so por
// chave_idempotencia.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marcacoes_cio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('vaca_id')->constrained('animais');
            $table->foreignId('rufiao_id')->constrained('animais');
            $table->dateTime('data_marcacao');
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marcacoes_cio');
    }
};
