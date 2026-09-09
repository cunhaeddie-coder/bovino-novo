<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-RECLASSIFICACAO.md §1/§2 - achado real: nenhum dos 15
// verticais anteriores precisou de categoria/finalidade em animais. Nascem
// aqui, nullable (nem todo Animal tem os dois definidos), texto livre - sem
// vocabulario fechado confirmado pelo produtor (VERTICAL-RECLASSIFICACAO.md
// §3).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animais', function (Blueprint $table) {
            $table->string('categoria')->nullable()->after('status');
            $table->string('finalidade')->nullable()->after('categoria');
        });
    }

    public function down(): void
    {
        Schema::table('animais', function (Blueprint $table) {
            $table->dropColumn(['categoria', 'finalidade']);
        });
    }
};
