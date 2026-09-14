<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-KYC.md §1/§2 - cache local de embargos ambientais. Nasce
// VAZIA nesta rodada (VERTICAL-KYC.md §7) - sem import job, sem chamada de
// rede real ao IBAMA, mesmo padrao ja usado pra SISBOV/B3 no projeto.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embargos_ibama', function (Blueprint $table) {
            $table->id();
            $table->string('documento');
            $table->string('situacao');
            $table->string('nome')->nullable();
            $table->string('municipio')->nullable();
            $table->string('estado', 2)->nullable();
            $table->date('data_embargo')->nullable();
            $table->timestamps();

            $table->index(['documento', 'situacao']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embargos_ibama');
    }
};
