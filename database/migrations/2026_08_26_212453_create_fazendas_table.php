<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-VENDA.md §1 — Fazenda é reaproveitada, não criada por este
// vertical, mas precisa existir primeiro: é a fronteira de INV-029.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fazendas', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fazendas');
    }
};
