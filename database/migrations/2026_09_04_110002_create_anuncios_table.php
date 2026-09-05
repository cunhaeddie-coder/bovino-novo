<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-MARKETPLACE.md §1/§2 — Anúncio é o Fato "publicação de um
// lote de Animais pra venda a terceiros" (MAPA-DOMINIO.md). preco_total é
// sempre o lote inteiro (1 preço por Anúncio — achado de preço por subgrupo
// em LAB-FA-024 é limitação aceita, fora do corte mínimo, §4). status nunca é
// um flag independente do produtor lembrar de atualizar — encerra sozinho na
// conclusão de uma Negociação (INV-023, §5).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anuncios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->decimal('preco_total', 14, 2);
            $table->string('status')->default('ativo');
            $table->date('publicado_em');
            $table->date('encerrado_em')->nullable();
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anuncios');
    }
};
