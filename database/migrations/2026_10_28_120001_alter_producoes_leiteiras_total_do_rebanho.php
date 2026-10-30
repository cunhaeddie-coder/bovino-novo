<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Decisao do produtor (28/10/2026, VERTICAL-PRODUCAO-LEITEIRA.md §8): o
// leite pro bezerro nao se mede (ele mama no peito) e e informacao inutil -
// sai a coluna. E o lancamento normal e o total de todas as vacas juntas;
// uma vaca separada so raramente - animal_id fica opcional (null = total
// do rebanho).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('producoes_leiteiras', function (Blueprint $table) {
            $table->dropColumn('quantidade_bezerro');
        });

        Schema::table('producoes_leiteiras', function (Blueprint $table) {
            $table->unsignedBigInteger('animal_id')->nullable()->change();
        });
    }

    // Volta so enquanto nenhum lancamento do total do rebanho existir
    // (animal_id null nao cabe de novo numa coluna obrigatoria).
    public function down(): void
    {
        Schema::table('producoes_leiteiras', function (Blueprint $table) {
            $table->unsignedBigInteger('animal_id')->nullable(false)->change();
        });

        Schema::table('producoes_leiteiras', function (Blueprint $table) {
            $table->decimal('quantidade_bezerro', 8, 2)->default(0);
        });
    }
};
