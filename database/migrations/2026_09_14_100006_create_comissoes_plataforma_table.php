<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-FRETE-LOGISTICA.md §1/§2 - primeira entidade que cruza
// deliberadamente o isolamento por Fazenda (INV-029/INV-045). A receita
// da propria plataforma sobre uma OrdemFrete aceita, nunca visivel por
// consulta escopada a Fazenda - so por Usuario.eh_administrador=true.
// percentual_aplicado e congelado na criacao (INV-043), nunca recalculado.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comissoes_plataforma', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_frete_id')->unique()->constrained('ordens_frete');
            $table->decimal('valor_comissao', 14, 2);
            $table->decimal('percentual_aplicado', 5, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comissoes_plataforma');
    }
};
