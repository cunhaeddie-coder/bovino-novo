<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-TITULAR.md §1/§2 - nullable de proposito (Regra Zero):
// nenhuma das 24 Fazendas ja criadas nos verticais anteriores precisa ser
// retroativamente preenchida. So bloqueia capacidades que dependem dele
// (KYC, e por decorrencia, publicar Anuncio/propor Negociacao).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fazendas', function (Blueprint $table) {
            $table->foreignId('titular_id')->nullable()->after('estado')->constrained('titulares');
        });
    }

    public function down(): void
    {
        Schema::table('fazendas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('titular_id');
        });
    }
};
