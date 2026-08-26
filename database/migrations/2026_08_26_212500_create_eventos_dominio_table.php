<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-VENDA.md §1/§9 — o Outbox (INV-027), genérico, reaproveitado
// por qualquer Fato futuro (Compra, Nascimento...), nunca recriado por
// vertical. fazenda_id é redundante com o fato, de propósito: nenhum
// consumidor deveria precisar de JOIN pra saber de qual Fazenda é o evento.
// UNIQUE por (fazenda_id, chave_idempotencia), não global — mesmo motivo de
// vendas.chave_idempotencia (revisão adversarial, 26/08/2026): a chave nasce
// do lado do cliente, colisão entre Fazendas diferentes não pode virar
// vazamento de qual evento é de quem.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos_dominio', function (Blueprint $table) {
            $table->id();
            $table->string('tipo');
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->string('chave_idempotencia');
            $table->json('payload');
            $table->string('status_consequencia')->default('pendente');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->index('status_consequencia');
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_dominio');
    }
};
