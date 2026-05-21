<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (filter_var(env('RUN_DEMO_SEEDER', false), FILTER_VALIDATE_BOOL)) {
            $this->call(CurrentVersionDemoSeeder::class);
        }

        if (filter_var(env('RUN_DEMO1_RICH_SEEDER', false), FILTER_VALIDATE_BOOL)) {
            $this->call(Demo1RichDataSeeder::class);
        }
    }
}
