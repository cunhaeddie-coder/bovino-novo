<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-ARRENDAMENTO.md §1/§2 - ParcelaArrendamento e cada parcela
// gerada sob comando explicito, uma por numero. UNIQUE(arrendamento_id,
// numero_parcela) garante 1 parcela por posicao por desenho de schema, sem
// precisar de chave_idempotencia propria (a chave natural ja existe), mesmo
// padrao de folhas_pagamento.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcelas_arrendamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('arrendamento_id')->constrained('arrendamentos');
            $table->unsignedInteger('numero_parcela');
            $table->decimal('valor', 14, 2);
            $table->dateTime('data_geracao');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['arrendamento_id', 'numero_parcela']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcelas_arrendamento');
    }
};
