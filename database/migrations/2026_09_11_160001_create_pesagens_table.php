<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-PESAGEM.md §1/§2 - Pesagem e o Fato "o peso de um Animal,
// numa data". Estrutura identica a producoes_leiteiras (Vertical 14) -
// animal_id como FK direta, uma linha por pesagem individual, mesmo em
// lote. Sem UNIQUE(animal_id, data_pesagem) - um Animal pode ser pesado
// mais de uma vez no mesmo dia (ex.: pesagem de conferencia), dedup fica
// so por chave_idempotencia.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pesagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('animal_id')->constrained('animais');
            $table->decimal('peso', 8, 2);
            $table->dateTime('data_pesagem');
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesagens');
    }
};
