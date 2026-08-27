<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// OBSERVABILIDADE-MINIMA-VENDA.md §2 — scanner_last_run_at. Tabela de uma
// linha só: sem isso, não dá pra distinguir "sem trabalho pendente" de
// "a varredura parou de rodar" (a pergunta 7 da especificação).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observabilidade_varredura', function (Blueprint $table) {
            $table->id();
            $table->timestamp('ultima_execucao_em')->nullable();
            $table->timestamps();
        });

        // Semeia a única linha que este mecanismo usa — o comando de
        // varredura sempre faz UPDATE nela, nunca INSERT novo.
        DB::table('observabilidade_varredura')->insert([
            'ultima_execucao_em' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('observabilidade_varredura');
    }
};
