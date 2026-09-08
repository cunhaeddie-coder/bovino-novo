<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-PROTOCOLO-REPRODUTIVO.md §1/§2 - ProtocoloReprodutivo e o
// Fato "um grupo de Animais em sincronizacao conjunta, passando por um
// ciclo IATF de 3 etapas". animal_ids em JSON, mesmo formato de
// vendas/mortes/gtas/separacoes_venda/nascimentos/eventos_saude.animal_ids.
// Nenhuma consequencia financeira/estoque - categoria de risco nova (maquina
// de estados sequencial).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocolos_reprodutivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->json('animal_ids');
            $table->dateTime('data_inicio');
            $table->string('status');
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocolos_reprodutivos');
    }
};
