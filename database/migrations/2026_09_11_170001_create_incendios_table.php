<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-INCENDIO.md §1/§2 - Incendio e o Fato "um Piquete sofreu
// um incendio, numa data" - fato historico puro, sem nenhum efeito sobre
// piquetes (sem alteracao de schema la, decisao explicita de "sem
// consequencia automatica"). Sem UNIQUE(piquete_id, data_incendio) -
// reincidencia e um fato real possivel, dedup fica so por
// chave_idempotencia.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incendios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('piquete_id')->constrained('piquetes');
            $table->dateTime('data_incendio');
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incendios');
    }
};
