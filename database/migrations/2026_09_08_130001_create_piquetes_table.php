<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-ROTACAO-PASTAGEM.md §1/§2 - Piquete e um Agregado proprio,
// como Lote (decisao do produtor) - nao uma faixa solta de texto. dias_descanso
// e o periodo minimo declarado, base do calculo de INV-040.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('piquetes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->string('nome');
            $table->unsignedInteger('dias_descanso');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('piquetes');
    }
};
