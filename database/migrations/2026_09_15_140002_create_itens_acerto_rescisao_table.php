<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-ACERTO-RESCISAO.md §1/§2 - cada item declarado pelo
// produtor (nome livre + valor), nunca calculado. Mesma multiplicidade de
// parcelas_arrendamento: N itens -> N ObrigacaoFinanceira independentes,
// nunca N FormaPagamento sob 1 unica Obrigacao. Sem chave_idempotencia
// propria - nasce inteiramente dentro da transacao do AcertoRescisao pai,
// mesmo raciocinio de parcelas_arrendamento/TransferenciaAnimal.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itens_acerto_rescisao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('acerto_rescisao_id')->constrained('acertos_rescisao');
            $table->string('nome');
            $table->decimal('valor', 14, 2);
            $table->date('vencimento');
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itens_acerto_rescisao');
    }
};
