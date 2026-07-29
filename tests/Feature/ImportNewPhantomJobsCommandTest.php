<?php

use App\Models\PhantomJob;
use Database\Seeders\PhantomJobSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('imports new phantom job bundles as tracked webp records', function () {
    $this->seed(PhantomJobSeeder::class);

    $targetRoot = storage_path('app/testing/new-phantom-jobs-'.Str::uuid());

    try {
        $this->artisan('phantom-jobs:import-new', [
            '--target-root' => $targetRoot,
        ])->assertSuccessful();

        $expectedJobs = [
            'Phantom Ninja' => 6,
            'Phantom White Mage' => 5,
            'Phantom Black Mage' => 5,
            'Phantom Dragoon' => 4,
            'Phantom Summoner' => 5,
            'Phantom Blue Mage' => 3,
            'Phantom Red Mage' => 6,
            'Phantom Necromancer' => 5,
        ];

        foreach ($expectedJobs as $name => $maxLevel) {
            $slug = Str::slug($name);
            $job = PhantomJob::query()->where('name', $name)->sole();

            expect($job->max_level)->toBe($maxLevel)
                ->and($job->icon_url)->toBe("/reference-icons/phantom-jobs/icons/{$slug}.webp")
                ->and($job->black_icon_url)->toBe("/reference-icons/phantom-jobs/black-icons/{$slug}.webp")
                ->and($job->transparent_icon_url)->toBe("/reference-icons/phantom-jobs/transparent-icons/{$slug}.webp")
                ->and($job->sprite_url)->toBe("/reference-icons/phantom-jobs/sprites/{$slug}.webp");

            foreach (['icons', 'black-icons', 'transparent-icons', 'sprites'] as $directory) {
                $path = $targetRoot.DIRECTORY_SEPARATOR.$directory.DIRECTORY_SEPARATOR.$slug.'.webp';

                expect(File::exists($path))->toBeTrue()
                    ->and(getimagesize($path)['mime'] ?? null)->toBe('image/webp');
            }
        }

        expect(PhantomJob::query()->where('name', 'Phantom Freelancer')->value('max_level'))->toBe(24);

        $this->artisan('phantom-jobs:import-new', [
            '--target-root' => $targetRoot,
        ])->assertSuccessful();

        expect(PhantomJob::query()->whereIn('name', array_keys($expectedJobs))->count())->toBe(8);
    } finally {
        File::deleteDirectory($targetRoot);
    }
});
