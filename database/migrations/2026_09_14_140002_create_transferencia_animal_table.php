<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-TRANSFERENCIA-FAZENDA.md §1/§4 - pivot: rastreia qual
// Animal da Fazenda origem virou qual Animal novo na Fazenda destino,
// 1:1 por linha (o animal da origem nunca e reaproveitado - vira apenas
// status=transferido).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transferencia_animal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transferencia_id')->constrained('transferencias_fazenda');
            $table->foreignId('animal_origem_id')->constrained('animais');
            $table->foreignId('animal_destino_id')->constrained('animais');
            $table->timestamps();

            $table->unique(['transferencia_id', 'animal_origem_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transferencia_animal');
    }
};
