<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-MARKETPLACE.md §1 — pivot. Um Anúncio referencia 1+ Animais
// REAIS do Rebanho, nunca um snapshot próprio — correção direta do achado do
// LAB-SA-017 (a tabela `animais` do Marketplace atual é desconectada do
// rebanho real).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anuncio_animal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anuncio_id')->constrained('anuncios');
            $table->foreignId('animal_id')->constrained('animais');
            $table->timestamps();

            $table->unique(['anuncio_id', 'animal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anuncio_animal');
    }
};
