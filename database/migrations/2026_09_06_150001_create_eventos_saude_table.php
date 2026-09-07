<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-EVENTO-SAUDE.md §1/§2 — EventoSaude e o Fato "um grupo de
// Animais recebeu a aplicacao de um Insumo, cuja consequencia e a mesma
// baixa de estoque que ConsumoInsumoService ja registra". animal_ids em
// JSON, mesmo formato de vendas/mortes/gtas/separacoes_venda/nascimentos.
// Nenhuma alteracao em insumos/consumos_insumo/animais - ponte literal.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos_saude', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->json('animal_ids');
            $table->string('descricao');
            $table->foreignId('consumo_insumo_id')->constrained('consumos_insumo');
            $table->dateTime('data_aplicacao');
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_saude');
    }
};
