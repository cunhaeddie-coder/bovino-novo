<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-NASCIMENTO.md §2 — tipo_origem/mae_id/peso_nascimento
// descrevem o Animal individual, não o evento de Nascimento (dois animais do
// mesmo nascimento coletivo podem ter maes/pesos diferentes) — por isso
// vivem aqui, nao em nascimentos.animal_ids. Todas nullable: so
// NascimentoService grava tipo_origem nesta rodada (CompraService nao e
// tocado, decisao explicita de escopo).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animais', function (Blueprint $table) {
            $table->string('tipo_origem')->nullable()->after('status');
            $table->foreignId('mae_id')->nullable()->after('tipo_origem')->constrained('animais');
            $table->decimal('peso_nascimento', 8, 2)->nullable()->after('mae_id');
        });
    }

    public function down(): void
    {
        Schema::table('animais', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mae_id');
            $table->dropColumn(['tipo_origem', 'peso_nascimento']);
        });
    }
};
