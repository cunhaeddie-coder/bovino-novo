<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-FRETE-LOGISTICA.md §1/§2 - OrdemFrete e o Fato central:
// a solicitacao de transporte, por leilao (motorista_id/valor_frete nulos
// ate o aceite) ou contratacao direta (ja nasce com os dois preenchidos).
// aceita_em/concluida_em/cancelada_em sao datas explicitas de transicao,
// mesmo padrao ja usado em negociacoes.confirmado_vendedor_em.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordens_frete', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->string('status')->default('aguardando_lance');
            $table->foreignId('motorista_id')->nullable()->constrained('motoristas');
            $table->decimal('valor_frete', 14, 2)->nullable();
            $table->string('chave_idempotencia');
            $table->dateTime('aceita_em')->nullable();
            $table->dateTime('concluida_em')->nullable();
            $table->dateTime('cancelada_em')->nullable();
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['fazenda_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordens_frete');
    }
};
