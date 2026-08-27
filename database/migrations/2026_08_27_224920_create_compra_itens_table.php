<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-COMPRA.md §3 — pivot, não JSON. Motivo técnico: os animais
// de uma Compra ainda NÃO existem no momento do pedido (a Compra é o fato
// que os cria) — diferente de vendas.animal_ids, que sempre referencia
// animais já existentes. Um pivot resolve isso na ordem natural da própria
// transação: cria as linhas de animais primeiro, depois insere compra_itens
// já com FK real, sem referência temporária nem reescrita de JSON.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compra_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')->constrained('compras');
            $table->foreignId('animal_id')->constrained('animais');
            $table->decimal('valor', 14, 2);
            $table->timestamps();

            $table->unique(['compra_id', 'animal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_itens');
    }
};
