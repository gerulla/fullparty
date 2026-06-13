<?php

namespace Database\Seeders;

use App\Models\RaidPosition;
use Illuminate\Database\Seeder;

class RaidPositionSeeder extends Seeder
{
    /**
     * Seed reusable raid positions.
     */
    public function run(): void
    {
        foreach ($this->raidPositions() as $position) {
            RaidPosition::updateOrCreate(
                ['key' => $position['key']],
                [
                    'name' => $position['name'],
                    'icon_url' => $position['icon_url'],
                    'sort_order' => $position['sort_order'],
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * @return array<int, array{key: string, name: string, icon_url: string|null, sort_order: int}>
     */
    private function raidPositions(): array
    {
        return [
            ['key' => 'mt', 'name' => 'Main Tank', 'icon_url' => null, 'sort_order' => 10],
            ['key' => 'ot', 'name' => 'Off Tank', 'icon_url' => null, 'sort_order' => 20],
            ['key' => 'h1', 'name' => 'Healer 1', 'icon_url' => null, 'sort_order' => 30],
            ['key' => 'h2', 'name' => 'Healer 2', 'icon_url' => null, 'sort_order' => 40],
            ['key' => 'm1', 'name' => 'DPS 1 / Melee 1', 'icon_url' => null, 'sort_order' => 50],
            ['key' => 'm2', 'name' => 'DPS 2 / Melee 2', 'icon_url' => null, 'sort_order' => 60],
            ['key' => 'r1', 'name' => 'DPS 3 / Phys Ranged', 'icon_url' => null, 'sort_order' => 70],
            ['key' => 'r2', 'name' => 'DPS 4 / Magic Ranged', 'icon_url' => null, 'sort_order' => 80],
        ];
    }
}
