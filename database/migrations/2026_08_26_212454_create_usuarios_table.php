<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ator de domínio (quem tem relação com uma Fazenda) — distinto da tabela
// `users` de autenticação que o Laravel já cria por padrão. Nenhuma UI/login
// entra ainda (EXCECAO-MATRIZ-FASE3.md), então não há decisão de identidade
// de auth a tomar aqui; `usuarios` só precisa existir pro núcleo do domínio.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
