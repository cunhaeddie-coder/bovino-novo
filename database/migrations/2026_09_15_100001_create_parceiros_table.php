<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-PARCEIROS-COMISSAO.md §1/§2 - um profissional externo
// (contador, veterinario, agronomo, tecnico agricola, corretor) que indica
// clientes a plataforma. Pertence a plataforma, nunca a uma Fazenda -
// mesmo eixo de Motorista/ComissaoPlataforma (Vertical 22).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parceiros', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('crm_crc')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parceiros');
    }
};
