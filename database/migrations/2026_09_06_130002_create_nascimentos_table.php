<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-NASCIMENTO.md §1/§2 — Nascimento e o Fato "um ou mais
// Animais surgiram numa Fazenda, sem custo de aquisicao". animal_ids em
// JSON, mesmo formato de vendas/mortes/gtas/separacoes_venda.animal_ids —
// os detalhes por animal (tipo_origem/mae_id/peso_nascimento) vivem em
// animais, nao aqui.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nascimentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->json('animal_ids');
            $table->dateTime('data_nascimento');
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nascimentos');
    }
};
