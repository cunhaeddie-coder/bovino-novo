<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-FRETE-LOGISTICA.md §1/§2 - chave/valor generico, nao uma
// tabela especifica de "comissao", pra nao precisar de outra migration na
// proxima config de plataforma que aparecer. Seed inicial:
// comissao_frete_percentual = 10 (valor observado em LAB-FA-029) -
// editavel so por administrador (ConfiguracaoPlataformaService).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracoes_plataforma', function (Blueprint $table) {
            $table->id();
            $table->string('chave')->unique();
            $table->string('valor');
            $table->foreignId('atualizado_por')->nullable()->constrained('usuarios');
            $table->timestamps();
        });

        DB::table('configuracoes_plataforma')->insert([
            'chave' => 'comissao_frete_percentual',
            'valor' => '10',
            'atualizado_por' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes_plataforma');
    }
};
