<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A relação usuário↔Fazenda que INV-029 exige sempre explícita, nunca inferida
// (Spike 005). `(usuario_id, fazenda_id)` é o par que toda checagem de acesso consulta.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('papeis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->string('papel');
            $table->timestamps();

            $table->index(['usuario_id', 'fazenda_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('papeis');
    }
};
