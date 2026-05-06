<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Deprecated compatibility alias for old deployment notes.
 *
 * Use CurrentVersionDemoSeeder for the current production demo dataset.
 */
class FullFlowDemoAccountSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CurrentVersionDemoSeeder::class);
    }
}
