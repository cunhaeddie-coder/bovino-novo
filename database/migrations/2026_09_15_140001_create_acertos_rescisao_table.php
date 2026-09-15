<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-ACERTO-RESCISAO.md §1/§4 - AcertoRescisao e o registro-pai
// de uma chamada de registrarCustoDesligamento(), mesmo papel de
// TransferenciaFazenda/Gta/SeparacaoVenda: 1 chave_idempotencia real,
// checada antes de qualquer efeito, tudo-ou-nada sobre a lista de itens.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acertos_rescisao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('funcionario_id')->constrained('funcionarios');
            $table->string('chave_idempotencia');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['funcionario_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acertos_rescisao');
    }
};
