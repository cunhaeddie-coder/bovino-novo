<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-CONFIGURACAO-FISCAL.md §1/§2 - o registro persistido do
// fato fiscal de uma Venda, 1:1. Fecha o achado mais antigo do laboratorio
// (LAB-SA-001/013/021 - lancamentos_fiscais sempre vazia no Atual).
// taxa_aplicada nullable - null quando nao havia ResponsavelFiscal
// configurado no momento (INV-063, nunca confundir "nao configurado" com
// "taxa zero").
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lancamentos_fiscais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venda_id')->unique()->constrained('vendas');
            $table->decimal('deducao_fiscal', 14, 2);
            $table->decimal('taxa_aplicada', 6, 4)->nullable();
            $table->boolean('fiscal_e_premissa');
            $table->dateTime('data_lancamento');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lancamentos_fiscais');
    }
};
