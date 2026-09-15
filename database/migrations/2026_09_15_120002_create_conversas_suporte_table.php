<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-SUPORTE-SUGESTOES-ADMIN.md §1/§2 - mesmo shape de
// sugestoes. Escalacao/multiplas mensagens fora de escopo (§7) - corte
// minimo e 1 mensagem inicial + 1 resposta.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversas_suporte', function (Blueprint $table) {
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
        Schema::dropIfExists('conversas_suporte');
    }
};
