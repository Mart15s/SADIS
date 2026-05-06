<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Backward-compatible entry point for older local commands.
 *
 * The maintained deployment demo dataset is CurrentVersionDemoSeeder.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CurrentVersionDemoSeeder::class);
    }
}
