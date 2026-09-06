<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-MORTE.md §1/§2 — Morte é o Fato "um ou mais Animais de uma
// Fazenda morreram, reduzindo o patrimônio, nunca gerando resultado
// financeiro" (INV-001, 2ª implementação real; INV-038 nova, causa sempre
// obrigatória). animal_ids em JSON, mesmo formato de vendas/gtas — morte
// coletiva é 1 evento referenciando N animais, nunca N eventos.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mortes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->json('animal_ids');
            $table->string('causa');
            $table->dateTime('data_morte');
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mortes');
    }
};
