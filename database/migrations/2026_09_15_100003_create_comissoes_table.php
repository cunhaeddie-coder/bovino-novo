<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-PARCEIROS-COMISSAO.md §1/§2 - uma parcela de comissao
// sobre uma Indicacao confirmada. valor/percentual_aplicado/
// valor_mensalidade_base congelados na criacao (INV-059), nunca
// recalculados depois - mesma disciplina de comissoes_plataforma
// (INV-043, Vertical 22). Nome distinto de comissoes_plataforma de
// proposito: conceitos diferentes (comissao de indicacao de Parceiro vs.
// comissao da plataforma sobre Frete).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comissoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicacao_id')->constrained('indicacoes');
            $table->unsignedInteger('numero_parcela');
            $table->decimal('valor', 14, 2);
            $table->decimal('percentual_aplicado', 5, 2);
            $table->decimal('valor_mensalidade_base', 14, 2);
            $table->timestamps();

            $table->index(['indicacao_id', 'id']);
            $table->unique(['indicacao_id', 'numero_parcela']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comissoes');
    }
};
