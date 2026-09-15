<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-SUPORTE-SUGESTOES-ADMIN.md §1/§2 - um pedido de
// melhoria/relato do produtor. resposta/respondida_em nullable - null ate
// um administrador responder (INV-065, mesmo padrao de
// Indicacao.confirmada_em, Vertical 28).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sugestoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->text('mensagem');
            $table->text('resposta')->nullable();
            $table->dateTime('respondida_em')->nullable();
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sugestoes');
    }
};
