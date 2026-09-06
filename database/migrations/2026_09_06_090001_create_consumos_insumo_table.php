<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-CONSUMO-INSUMO.md §1/§2 — Consumo é o Fato "uma quantidade
// de Insumo foi de fato usada, dando baixa real no estoque" (INV-004). Sem
// vínculo a Agregado/Animal (decisão do produtor, VERTICAL-CONSUMO-INSUMO.md
// §2) — só a baixa. data_consumo já nasce com hora (GATE-DECISAO-DOMINIO-
// DATA-HORA.md).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumos_insumo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('insumo_id')->constrained('insumos');
            $table->decimal('quantidade', 14, 2);
            $table->dateTime('data_consumo');
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->index('insumo_id');
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumos_insumo');
    }
};
