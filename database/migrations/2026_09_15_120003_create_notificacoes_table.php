<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-SUPORTE-SUGESTOES-ADMIN.md §1/§2 - o aviso disparado
// quando Sugestao/ConversaSuporte muda de estado. usuario_id nullable -
// null quando para_administrador=true (INV-066, visivel a qualquer admin,
// nunca uma pessoa especifica - mesmo espirito de INV-045).
// sugestao_id/conversa_suporte_id mutuamente exclusivos (guard de
// aplicacao). UNIQUE por tipo em cada FK - garante que reprocessar o
// mesmo evento nunca duplica a Notificacao (mesmo padrao de
// LancamentoFiscal/Lancamento).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios');
            $table->boolean('para_administrador');
            $table->string('tipo');
            $table->text('mensagem');
            $table->foreignId('sugestao_id')->nullable()->constrained('sugestoes');
            $table->foreignId('conversa_suporte_id')->nullable()->constrained('conversas_suporte');
            $table->dateTime('lida_em')->nullable();
            $table->timestamps();

            $table->unique(['sugestao_id', 'tipo']);
            $table->unique(['conversa_suporte_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacoes');
    }
};
