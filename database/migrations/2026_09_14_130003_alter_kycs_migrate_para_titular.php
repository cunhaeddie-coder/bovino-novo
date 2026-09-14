<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-KYC.md §8 (reabertura) / SCHEMA-CONTRATO-TITULAR.md §1 -
// kycs.fazenda_id vira kycs.titular_id - um Kyc por Titular, nao por
// Fazenda. documento/tipo_documento saem de kycs (vivem so em titulares
// agora, fonte unica).
//
// Achado real (Spike 007, MySQL real): SQLite exige dropUnique() antes de
// derrubar a FK (alter_fornecedores_add_fazenda_id.php); MySQL exige
// exatamente o CONTRARIO - "Cannot drop index needed in a foreign key
// constraint" se a UNIQUE for derrubada antes da FK que a usa. Ordem que
// funciona nos dois motores: dropForeign() primeiro (solta a dependencia),
// dropUnique() depois, dropColumn() por ultimo - nunca dropConstrainedForeignId()
// (que tenta os dois numa ordem so, incompativel com MySQL aqui).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kycs', function (Blueprint $table) {
            $table->dropForeign(['fazenda_id']);
            $table->dropUnique(['fazenda_id']);
            $table->dropColumn(['fazenda_id', 'documento', 'tipo_documento']);
            $table->foreignId('titular_id')->unique()->constrained('titulares');
        });
    }

    public function down(): void
    {
        Schema::table('kycs', function (Blueprint $table) {
            $table->dropForeign(['titular_id']);
            $table->dropUnique(['titular_id']);
            $table->dropColumn('titular_id');
            $table->string('documento')->nullable();
            $table->string('tipo_documento')->nullable();
            $table->foreignId('fazenda_id')->unique()->constrained('fazendas');
        });
    }
};
