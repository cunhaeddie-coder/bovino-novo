<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-FRETE-LOGISTICA.md §1/§2 - PropostaFrete e o lance de um
// Motorista sobre uma OrdemFrete em leilao. Terminal a partir de
// status in (aceita, recusada) - guard em PropostaFrete::booted().
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('propostas_frete', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_frete_id')->constrained('ordens_frete');
            $table->foreignId('motorista_id')->constrained('motoristas');
            $table->decimal('valor_proposto', 14, 2);
            $table->string('status')->default('pendente');
            $table->timestamps();

            $table->index(['ordem_frete_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('propostas_frete');
    }
};
