<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-NUTRICAO.md §1/§2 - eventos_saude (Vertical 11, ja
// estendida pelo Vertical 18) ganha 1 coluna nullable pra declarar a epoca
// do ano (aguas/seca, texto livre) de uma aplicacao nutricional. Extensao
// de schema, nao tabela nova - epoca e null pra qualquer Evento de Saude
// comum, incluindo as vacinacoes do Vertical 18.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos_saude', function (Blueprint $table) {
            $table->string('epoca')->nullable()->after('tipo_vacina');
        });
    }

    public function down(): void
    {
        Schema::table('eventos_saude', function (Blueprint $table) {
            $table->dropColumn('epoca');
        });
    }
};
