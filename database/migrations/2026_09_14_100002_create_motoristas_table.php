<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-FRETE-LOGISTICA.md §1/§2 - Motorista e o 1o papel de
// usuario fora do eixo Fazenda/Fornecedor/Comprador. Cadastro nasce sempre
// pendente - so acessa fretes (dar lance ou ser contratado direto) depois
// de aprovado por um administrador (INV-042). fazenda_id e vinculo
// opcional (quando existe, conta como criterio de aprovacao, decisao
// humana, sem regra automatizada).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motoristas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->unique()->constrained('usuarios');
            $table->foreignId('fazenda_id')->nullable()->constrained('fazendas');
            $table->string('documento');
            $table->string('status')->default('pendente');
            $table->dateTime('aprovado_em')->nullable();
            $table->foreignId('aprovado_por')->nullable()->constrained('usuarios');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motoristas');
    }
};
