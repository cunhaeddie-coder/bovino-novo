<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-VENDA.md §7 — fazenda_id nunca nullable, índice de consulta
// principal nasce composto (fazenda_id, id), nunca solto em id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->unsignedInteger('qtd_animais');
            $table->decimal('custo_aquisicao', 14, 2);
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lotes');
    }
};
