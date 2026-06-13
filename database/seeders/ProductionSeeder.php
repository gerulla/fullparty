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
            CharacterClassSeeder::class,
            PhantomJobSeeder::class,
            RaidPositionSeeder::class,
            LargeContentActivityTypeSeeder::class,
            ExtremeActivityTypeSeeder::class,
            SavageActivityTypeSeeder::class,
            UltimateActivityTypeSeeder::class,
        ]);
    }
}
