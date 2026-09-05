<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-MARKETPLACE.md §1 — achado real ao planejar a implementação:
// CompraService::registrar() exige fornecedor_id, mas Fornecedor não tinha
// nenhuma relação com Fazenda. Na ponte do Marketplace, quem "vende" pro lado
// comprador é outra Fazenda cadastrada no sistema, não um fornecedor externo.
// fazenda_id nullable preserva todo Fornecedor externo existente (NULL) e
// permite representar uma Fazenda parceira quando a Compra nasce do
// Marketplace — a ponte busca ou cria esse Fornecedor via firstOrCreate().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fornecedores', function (Blueprint $table) {
            $table->foreignId('fazenda_id')->nullable()->unique()->constrained('fazendas');
        });
    }

    public function down(): void
    {
        Schema::table('fornecedores', function (Blueprint $table) {
            // Achado real (rollback testado, 04/09/2026): dropConstrainedForeignId()
            // sozinho não basta no SQLite quando a coluna também tem um índice
            // único explícito — o rebuild de tabela do SQLite tenta recriar o
            // índice antes de perceber que a coluna já não existe mais. O
            // índice único precisa ser derrubado primeiro, explicitamente.
            $table->dropUnique(['fazenda_id']);
            $table->dropConstrainedForeignId('fazenda_id');
        });
    }
};
