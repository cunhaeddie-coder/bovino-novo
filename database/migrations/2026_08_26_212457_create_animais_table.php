<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-VENDA.md §7 — fazenda_id nunca nullable; a baixa de animal
// (VendaService) filtra por fazenda_id na mesma instrução de UPDATE (Spike 006, a14/a15/a16).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('animais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('lote_id')->constrained('lotes');
            $table->string('status')->default('ativo');
            $table->date('data_saida')->nullable();
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->index(['fazenda_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('animais');
    }
};
