<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCHEMA-CONTRATO-PROTOCOLO-REPRODUTIVO.md §1/§2 - as 3 etapas fixas do
// IATF, uma linha por tipo, nascidas juntas com o ProtocoloReprodutivo.
// UNIQUE(protocolo_reprodutivo_id, tipo) garante as 3, nunca duplicadas,
// por desenho de schema.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etapas_protocolo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas');
            $table->foreignId('protocolo_reprodutivo_id')->constrained('protocolos_reprodutivos');
            $table->string('tipo');
            $table->dateTime('data_prevista');
            $table->dateTime('data_realizada')->nullable();
            $table->timestamps();

            $table->index(['fazenda_id', 'id']);
            $table->unique(['protocolo_reprodutivo_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etapas_protocolo');
    }
};
