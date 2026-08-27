<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-COMPRA.md §1 — mínimo (id, nome), global, não escopado por
// Fazenda, mesmo padrão de `usuarios`. Isolamento é garantido pela Compra,
// não pelo Fornecedor. Ciclo de vida completo do cadastro é fora de escopo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fornecedores', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fornecedores');
    }
};
