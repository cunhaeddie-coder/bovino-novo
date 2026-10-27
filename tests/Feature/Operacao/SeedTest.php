<?php

namespace Tests\Feature\Operacao;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** `db:seed` quebrava com App\Models\User, removido no Vertical 33. */
class SeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_db_seed_roda_sem_erro(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
    }
}
