<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-FAZENDA-PERFIL-PUBLICO.md §1/§2 - perfil publico de uma
// Fazenda ja existente. slug nullable ate a 1a publicacao, UNIQUE
// (null-safe). ativo default false - mesmo comportamento observado no
// Atual (Fazenda nasce sempre despublicada).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fazendas', function (Blueprint $table) {
            $table->text('descricao')->nullable()->after('estado');
            $table->string('logo_url')->nullable()->after('descricao');
            $table->string('website')->nullable()->after('logo_url');
            $table->string('raca_principal')->nullable()->after('website');
            $table->string('slug')->nullable()->unique()->after('raca_principal');
            $table->boolean('ativo')->default(false)->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('fazendas', function (Blueprint $table) {
            // Mesmo achado real já documentado em
            // alter_fornecedores_add_fazenda_id.php: no SQLite, o índice
            // único precisa ser derrubado explicitamente antes da coluna,
            // senão o rebuild de tabela tenta recriar o índice depois que
            // a coluna já não existe mais.
            $table->dropUnique(['slug']);
            $table->dropColumn(['descricao', 'logo_url', 'website', 'raca_principal', 'slug', 'ativo']);
        });
    }
};
