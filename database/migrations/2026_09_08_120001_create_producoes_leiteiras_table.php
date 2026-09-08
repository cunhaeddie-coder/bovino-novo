<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-PRODUCAO-LEITEIRA.md §1/§2 - ProducaoLeiteira e o Fato "a
// quantidade de leite produzida por uma vaca individual num dia,
// distinguindo vendido de destinado ao bezerro". animal_id e um unico
// Animal (fato individual por natureza, diferente da maioria dos verticais
// recentes que usam animal_ids em array). Sem UNIQUE(animal_id,
// data_producao) - dedup fica so por chave_idempotencia, mesmo padrao de
// Morte/Nascimento/Evento de Saude.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producoes_leiteiras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('animal_id')->constrained('animais');
            $table->dateTime('data_producao');
            $table->decimal('quantidade_total', 8, 2);
            $table->decimal('quantidade_vendida', 8, 2);
            $table->decimal('quantidade_bezerro', 8, 2);
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producoes_leiteiras');
    }
};
