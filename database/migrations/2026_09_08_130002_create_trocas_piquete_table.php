<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-ROTACAO-PASTAGEM.md §1/§2/§3 - TrocaPiquete e o Fato de
// Dominio: um Lote sai de um piquete_origem_id (nullable - primeira alocacao
// nunca teve origem) e entra num piquete_destino_id, numa data. descanso_
// interrompido e sempre calculado e gravado (INV-040), nunca null.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trocas_piquete', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('lote_id')->constrained('lotes');
            $table->foreignId('piquete_origem_id')->nullable()->constrained('piquetes');
            $table->foreignId('piquete_destino_id')->constrained('piquetes');
            $table->dateTime('data_troca');
            $table->boolean('descanso_interrompido');
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->index(['piquete_origem_id', 'data_troca']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trocas_piquete');
    }
};
