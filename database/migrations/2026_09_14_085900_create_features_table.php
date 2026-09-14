<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Migrations\PennantMigration;

// Migration padrão do laravel/pennant (vendor/laravel/pennant/database/
// migrations/2022_11_01_000001_create_features_table.php) — copiada, não
// publicada via vendor:publish (tag não resolveu nesta versão), conteúdo
// idêntico ao do pacote. Primeira vez que Pennant é usado de verdade neste
// projeto (DECISOES-ABERTAS.md item 6, camada de capacidade) —
// IntelligenciaMercadoService (Vertical 21) é o primeiro consumidor real.
return new class extends PennantMigration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('scope');
            $table->text('value');
            $table->timestamps();

            $table->unique(['name', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
