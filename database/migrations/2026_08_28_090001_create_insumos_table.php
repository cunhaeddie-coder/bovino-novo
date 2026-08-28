<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-COMPRA-INSUMO.md §1 — recurso/agregado (análogo a `lotes`),
// não é fato. Escopado por Fazenda (diferente de `fornecedores`, global): o
// catálogo de Insumo de uma Fazenda é independente do de outra. `nome`
// UNIQUE por Fazenda — não é decisão de domínio, é salvaguarda técnica pro
// próprio risco que motivou a decisão do produtor (§8.1 do Vertical: sempre
// buscar/selecionar, nunca casar por nome depois) — evita duplicata
// acidental mesmo com a UI cumprindo a regra corretamente.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insumos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->string('nome');
            $table->decimal('quantidade', 14, 2)->default(0);
            $table->decimal('valor_referencia', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insumos');
    }
};
