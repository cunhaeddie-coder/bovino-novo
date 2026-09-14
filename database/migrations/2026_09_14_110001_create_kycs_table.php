<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-KYC.md §1/§2 - Kyc e o Fato "verificacao de identidade de
// uma Fazenda", 1:1 com Fazenda (nao com Usuario - Comprador/Vendedor no
// Bovino Novo sao Fazendas, SCHEMA-CONTRATO-MARKETPLACE.md §2). Sem guard
// de terminalidade - resubmissao e esperada e valida (VERTICAL-KYC.md §3).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kycs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->unique()->constrained('fazendas');
            $table->string('documento');
            $table->string('tipo_documento');
            $table->string('status');
            $table->string('motivo_reprovacao')->nullable();
            $table->dateTime('verificado_em');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kycs');
    }
};
