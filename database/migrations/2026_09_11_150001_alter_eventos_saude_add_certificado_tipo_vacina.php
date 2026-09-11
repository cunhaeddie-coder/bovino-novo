<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-VACINACAO-OBRIGATORIA.md §1/§2 - eventos_saude (Vertical
// 11) ganha 2 colunas nullable pra declarar quando uma aplicacao e, tambem,
// uma vacina fiscalizavel por lei. Extensao de schema, nao tabela nova -
// certificado/tipo_vacina sao null pra qualquer Evento de Saude comum.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos_saude', function (Blueprint $table) {
            $table->string('certificado')->nullable()->after('descricao');
            $table->string('tipo_vacina')->nullable()->after('certificado');
        });
    }

    public function down(): void
    {
        Schema::table('eventos_saude', function (Blueprint $table) {
            $table->dropColumn(['certificado', 'tipo_vacina']);
        });
    }
};
