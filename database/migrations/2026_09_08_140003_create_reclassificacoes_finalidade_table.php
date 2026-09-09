<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-RECLASSIFICACAO.md §1/§2 - mesmo formato de
// reclassificacoes_categoria, fato separado (decisao do produtor: categoria
// e finalidade nunca reclassificam juntas na mesma chamada).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reclassificacoes_finalidade', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->json('animal_ids');
            $table->string('finalidade_nova');
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reclassificacoes_finalidade');
    }
};
