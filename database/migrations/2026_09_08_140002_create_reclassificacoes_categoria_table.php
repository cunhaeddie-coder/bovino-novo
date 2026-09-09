<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-RECLASSIFICACAO.md §1/§2 - N Animais tem sua categoria
// mudada pro mesmo valor novo, numa unica operacao (INV-010). animal_ids em
// JSON, mesmo formato de vendas/gtas/mortes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reclassificacoes_categoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->json('animal_ids');
            $table->string('categoria_nova');
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reclassificacoes_categoria');
    }
};
