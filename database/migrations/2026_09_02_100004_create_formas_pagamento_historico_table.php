<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-FORMA-PAGAMENTO.md §6 — trilha de edição, mecanismo
// diferente de INV-026: formas_pagamento permanece mutável (UPDATE direto é
// aceitável aqui, decisão de domínio distinta — um acordo em aberto, sujeito
// a renegociação real, ao contrário de Venda/Compra que são fatos já
// concluídos). Uma linha por edição, snapshot do estado ANTERIOR à mudança.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formas_pagamento_historico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forma_pagamento_id')->constrained('formas_pagamento');
            $table->string('nome');
            $table->decimal('valor', 14, 2);
            $table->string('unidade');
            $table->date('vencimento');
            $table->timestamp('alterado_em');
            $table->foreignId('alterado_por')->constrained('usuarios');

            $table->index(['forma_pagamento_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formas_pagamento_historico');
    }
};
