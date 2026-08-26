<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VERTICAL-VENDA.md §3b — Configuração Fiscal mínima: um Responsável Fiscal por
// Fazenda (INV-019). taxa_venda_animal fica NULL enquanto a regra real (ICMS/
// Funrural) não for declarada pelo produtor — taxa_e_premissa nunca deixa isso
// passar por fato. Ver VERTICAL-VENDA.md §10.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsavel_fiscal', function (Blueprint $table) {
            $table->foreignId('fazenda_id')->primary()->constrained('fazendas');
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->decimal('taxa_venda_animal', 6, 4)->nullable();
            $table->boolean('taxa_e_premissa')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responsavel_fiscal');
    }
};
