<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-INTELIGENCIA-MERCADO.md §1 — resolve a lacuna que
// SCHEMA-CONTRATO-VENDA.md §11 já tinha nomeado e deixado deliberadamente em
// aberto: consulta de Venda pelo lado do Animal. Mesmo padrão de
// anuncio_animal (Marketplace) — sem fazenda_id próprio, já implícito via
// venda_id/animal_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venda_animal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venda_id')->constrained('vendas');
            $table->foreignId('animal_id')->constrained('animais');
            $table->timestamps();

            $table->unique(['venda_id', 'animal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venda_animal');
    }
};
