<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Data every environment needs. Demo data lives in DevelopmentSeeder, which
 * refuses to run in production.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RbacSeeder::class);
    }
}
