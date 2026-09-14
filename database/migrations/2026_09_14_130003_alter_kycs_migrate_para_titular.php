<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-KYC.md §8 (reabertura) / SCHEMA-CONTRATO-TITULAR.md §1 -
// kycs.fazenda_id vira kycs.titular_id - um Kyc por Titular, nao por
// Fazenda. documento/tipo_documento saem de kycs (vivem so em titulares
// agora, fonte unica). Mesmo achado real ja documentado em
// alter_fornecedores_add_fazenda_id.php: no SQLite, o indice unico precisa
// ser derrubado explicitamente antes da coluna.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kycs', function (Blueprint $table) {
            $table->dropUnique(['fazenda_id']);
            $table->dropConstrainedForeignId('fazenda_id');
            $table->dropColumn(['documento', 'tipo_documento']);
            $table->foreignId('titular_id')->unique()->constrained('titulares');
        });
    }

    public function down(): void
    {
        Schema::table('kycs', function (Blueprint $table) {
            $table->dropUnique(['titular_id']);
            $table->dropConstrainedForeignId('titular_id');
            $table->string('documento')->nullable();
            $table->string('tipo_documento')->nullable();
            $table->foreignId('fazenda_id')->unique()->constrained('fazendas');
        });
    }
};
