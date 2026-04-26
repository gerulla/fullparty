<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductionSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's production-safe reference data.
     */
    public function run(): void
    {
        $this->call([
            ProductionAdminUserSeeder::class,
            CharacterClassSeeder::class,
            PhantomJobSeeder::class,
            ActivityTypeSeeder::class,
        ]);
    }
}
