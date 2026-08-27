<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-COMPRA.md §1/§7 — genérica o suficiente pra não ser
// recriada por vertical (mesmo espírito de eventos_dominio), hoje só usada
// por Compra. fazenda_id é redundante com a Compra, de propósito — nenhum
// consumidor deveria precisar de JOIN pra saber de qual Fazenda é a
// obrigação. compra_id nunca solta (resposta ao achado antigo de duplicação
// não confirmado em execução real, LAB-SA-012).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('obrigacoes_financeiras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('compra_id')->constrained('compras');
            $table->decimal('valor', 14, 2);
            $table->string('status')->default('pago');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('obrigacoes_financeiras');
    }
};
