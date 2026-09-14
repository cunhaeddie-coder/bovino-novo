<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-TITULAR.md §1/§2 - Titular e a identidade juridica (CPF
// ou CNPJ) que possui uma ou mais Fazendas. Nasce da correcao de processo
// da pergunta 44 (INV-022, nunca confirmada de verdade ate 14/09/2026).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('titulares', function (Blueprint $table) {
            $table->id();
            $table->string('documento')->unique();
            $table->string('tipo_documento');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titulares');
    }
};
