<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-GTA.md §1/§2 — GTA é o Fato "documento legalmente
// obrigatório de transporte, cuja conclusão produz a mesma Venda que
// qualquer outro canal" (INV-002/INV-003, INV-037 nova). animal_ids em JSON,
// mesmo formato de vendas.animal_ids — não precisa de pivot no corte mínimo.
// valor_bruto declarado direto na GTA: ela É o canal de venda aqui, não um
// documento acessório de uma venda já registrada em outro lugar.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gtas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->json('animal_ids');
            $table->string('destino');
            $table->unsignedInteger('quantidade_declarada');
            $table->decimal('valor_bruto', 14, 2);
            $table->string('status');
            $table->dateTime('data_emissao');
            $table->dateTime('data_conclusao')->nullable();
            $table->foreignId('venda_id')->nullable()->constrained('vendas');
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gtas');
    }
};
