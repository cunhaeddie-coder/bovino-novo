<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-MARKETPLACE.md §2/§3/§5 — Negociação formaliza a entidade
// que GATE-PRIORIZACAO-VERTICAL-3.md já tinha identificado faltando no
// vocabulário. Vocabulário mínimo: só os campos que existem ANTES da Venda
// existir (anuncio_id, fazenda_compradora_id, preco_proposto, status) — tudo
// pós-conclusão é responsabilidade de Venda/Compra reaproveitadas.
//
// confirmado_vendedor_em/confirmado_comprador_em (§5, corrigido 04/09/2026):
// a ponte não pode ser uma única transação chamando VendaService::registrar()
// e CompraService::registrar() com um usuário só — cada um exige autorização
// de uma Fazenda diferente, e nenhum usuário real tem relação com as duas.
// Confirmação em 2 fases, independentes: venda_id nasce da confirmação do
// vendedor, compra_id da confirmação do comprador. concluida_em só quando as
// duas existirem (INV-034).
//
// chave_idempotencia escopada por fazenda_compradora_id (quem propõe, mesmo
// padrão de Venda/Compra escoparem pela Fazenda que registra o fato).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('negociacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anuncio_id')->constrained('anuncios');
            $table->foreignId('fazenda_compradora_id')->constrained('fazendas');
            $table->decimal('preco_proposto', 14, 2);
            $table->string('status');
            $table->string('chave_idempotencia');
            $table->dateTime('confirmado_vendedor_em')->nullable();
            $table->dateTime('confirmado_comprador_em')->nullable();
            $table->foreignId('venda_id')->nullable()->constrained('vendas');
            $table->foreignId('compra_id')->nullable()->constrained('compras');
            $table->dateTime('concluida_em')->nullable();
            $table->timestamps();

            $table->index(['anuncio_id', 'id']);
            $table->unique(['fazenda_compradora_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('negociacoes');
    }
};
