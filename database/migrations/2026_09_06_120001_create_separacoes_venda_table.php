<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-SEPARACAO-VENDA.md §1/§2 — SeparacaoVenda é o Fato "4º e
// último canal de venda desconectado (Ordem de Serviço, finalidade
// venda_direta), cuja conclusão produz a mesma Venda que qualquer outro
// canal" (INV-002/INV-003). animal_ids em JSON, mesmo formato de
// vendas.animal_ids/gtas.animal_ids. Diferente de GTA, não existe
// quantidade_declarada separada — count(animal_ids) é a quantidade, sempre.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('separacoes_venda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->json('animal_ids');
            $table->decimal('valor_total', 14, 2);
            $table->string('status');
            $table->dateTime('data_separacao');
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
        Schema::dropIfExists('separacoes_venda');
    }
};
