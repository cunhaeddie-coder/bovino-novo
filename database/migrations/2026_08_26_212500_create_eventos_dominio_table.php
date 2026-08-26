<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-VENDA.md §1/§9 — o Outbox (INV-027), genérico, reaproveitado
// por qualquer Fato futuro (Compra, Nascimento...), nunca recriado por
// vertical. fazenda_id é redundante com o fato, de propósito: nenhum
// consumidor deveria precisar de JOIN pra saber de qual Fazenda é o evento.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos_dominio', function (Blueprint $table) {
            $table->id();
            $table->string('tipo');
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->string('chave_idempotencia')->unique();
            $table->json('payload');
            $table->string('status_consequencia')->default('pendente');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->index('status_consequencia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_dominio');
    }
};
