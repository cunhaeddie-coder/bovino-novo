<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-AUTENTICACAO.md §1/§2 - Usuario vira o proprio
// Authenticatable do Sanctum, sem model de credenciais separado. email/
// celular nullable + UNIQUE quando presente (guard de aplicacao exige pelo
// menos um quando senha esta preenchida, nao os dois sempre); senha
// nullable (Usuarios de dominio puro, sem login, continuam validos).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('email')->nullable()->unique()->after('nome');
            $table->string('celular')->nullable()->unique()->after('email');
            $table->string('senha')->nullable()->after('celular');
        });
    }

    public function down(): void
    {
        // Achado real (rodando up->down->up): SQLite tropeça ao dropar
        // multiplas colunas com indice UNIQUE na mesma chamada de
        // dropColumn() ("no such column: email" apos a 1a coluna) - o
        // rebuild interno da tabela emulado pelo Laravel perde a coluna
        // seguinte antes de reconstruir os indices. dropUnique() explicito
        // antes do dropColumn() evita o problema.
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropUnique(['celular']);
        });

        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn(['email', 'celular', 'senha']);
        });
    }
};
