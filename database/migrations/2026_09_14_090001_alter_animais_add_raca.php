<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VERTICAL-INTELIGENCIA-MERCADO.md §6 — nullable, sem backfill: Regra Zero
// (não inventar raça de animal já gravado nos 20 verticais anteriores).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animais', function (Blueprint $table) {
            $table->string('raca')->nullable()->after('finalidade');
        });
    }

    public function down(): void
    {
        Schema::table('animais', function (Blueprint $table) {
            $table->dropColumn('raca');
        });
    }
};
