<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

// Sem dado semeado de propósito. O seeder gerado pelo Laravel usava
// App\Models\User, removido no Vertical 33 (Usuario virou o Authenticatable),
// e `db:seed` quebrava. A homologação começa vazia: o usuário se cadastra em
// POST /api/auth/registrar e a Fazenda vem de `php artisan fazenda:criar`.
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        //
    }
}
