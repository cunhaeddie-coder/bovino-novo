<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-COMPRA-INSUMO.md §3 — pivot, mas por motivo DIFERENTE do
// de compra_itens (Animal): aqui o Insumo já existe antes do pedido (§8.1
// do Vertical), não há problema de ordem de criação. O pivot se justifica
// pela necessidade real de atualizar insumos.valor_referencia/quantidade
// por item (resposta direta a LAB-SA-003/LAB-FA-015), não por criação
// diferida. valor_unitario > 0 e quantidade > 0 exigidos em toda linha
// (§12) — valor zero não é Compra, é Amostra Grátis (MAPA-DOMINIO.md),
// fato diferente, fora deste schema.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compra_insumo_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_insumo_id')->constrained('compras_insumo');
            $table->foreignId('insumo_id')->constrained('insumos');
            $table->decimal('quantidade', 14, 2);
            $table->decimal('valor_unitario', 14, 2);
            $table->timestamps();

            $table->unique(['compra_insumo_id', 'insumo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_insumo_itens');
    }
};
