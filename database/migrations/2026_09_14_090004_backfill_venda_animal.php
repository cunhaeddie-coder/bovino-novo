<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// SCHEMA-CONTRATO-INTELIGENCIA-MERCADO.md §5 — migration de DADOS, não de
// schema: popula venda_animal a partir do animal_ids que cada Venda já
// existente já carrega. Deriva de dado já gravado, não inventa nenhum fato
// novo (mesma disciplina de qualquer backfill neste projeto). Cobre tanto
// vendas originais quanto correções — cada linha de vendas tem seu próprio
// animal_ids, cada uma ganha sua própria entrada na pivot.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('vendas')->orderBy('id')->chunk(200, function ($vendas) {
            foreach ($vendas as $venda) {
                $animalIds = json_decode($venda->animal_ids, true) ?? [];
                if (empty($animalIds)) {
                    continue;
                }

                $linhas = array_map(fn (int $animalId) => [
                    'venda_id' => $venda->id,
                    'animal_id' => $animalId,
                    'created_at' => $venda->created_at,
                    'updated_at' => $venda->updated_at,
                ], $animalIds);

                DB::table('venda_animal')->insertOrIgnore($linhas);
            }
        });
    }

    public function down(): void
    {
        // Backfill de dados derivados — reverter é só esvaziar a pivot,
        // nunca apaga o animal_ids original em `vendas` (fonte de verdade).
        DB::table('venda_animal')->truncate();
    }
};
