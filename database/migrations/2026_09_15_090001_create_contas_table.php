<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-CARTEIRA.md §1/§2 - uma Conta de um Titular: 'bovino'
// (unica, autoconsolida o que o Bovino Novo processa) ou 'externa' (N por
// Titular, lancamento manual). Sem UNIQUE(titular_id, tipo) no schema (nao
// portavel pra so um valor de tipo) - INV-055 garantida por firstOrCreate
// + catch(QueryException)+retry na aplicacao, mesmo mecanismo ja provado
// em Kyc/FazendaPerfil/Titular.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('titular_id')->constrained('titulares');
            $table->string('tipo');
            $table->string('nome');
            // Nullable: só 'externa' usa (criação explícita do produtor,
            // precisa de reenvio-detecção como toda ação de criação do
            // projeto); 'bovino' nasce via firstOrCreate, sem chave. NULL
            // múltiplo é seguro no índice único (MySQL/SQLite tratam cada
            // NULL como distinto), mesmo padrão de fornecedores.fazenda_id.
            $table->string('chave_idempotencia')->nullable();
            $table->timestamps();

            $table->index(['titular_id', 'id']);
            $table->unique(['titular_id', 'chave_idempotencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contas');
    }
};
