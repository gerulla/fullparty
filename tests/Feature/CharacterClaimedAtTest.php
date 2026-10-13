<?php

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('backfills ownership claims from existing verification dates without changing existing data', function () {
    $migration = require database_path('migrations/2026_10_12_000001_add_claimed_at_to_characters.php');
    $migration->down();

    $verified = Character::factory()->primary()->create(['verified_at' => '2026-07-01 00:41:07']);
    $unverified = Character::factory()->unverified()->create();
    $originalVerified = $verified->getRawOriginal();
    $originalUnverified = $unverified->getRawOriginal();

    $migration->up();

    expect($verified->fresh()->claimed_at->format('Y-m-d H:i:s'))->toBe('2026-07-01 00:41:07')
        ->and($verified->fresh()->isClaimed())->toBeTrue()
        ->and($verified->fresh()->only(array_keys($originalVerified)))->toEqual($verified->only(array_keys($originalVerified)))
        ->and($unverified->fresh()->claimed_at)->toBeNull()
        ->and($unverified->fresh()->isClaimed())->toBeFalse()
        ->and($unverified->fresh()->only(array_keys($originalUnverified)))->toEqual($unverified->only(array_keys($originalUnverified)));

    $migration->down();
    expect(Schema::hasColumn('characters', 'claimed_at'))->toBeFalse()
        ->and($verified->fresh()->verified_at->format('Y-m-d H:i:s'))->toBe('2026-07-01 00:41:07');
    $migration->up();
});

it('allows new verified-only and unverified characters without automatically claiming them', function () {
    $verified = Character::factory()->create(['verified_at' => now()]);
    $unverified = Character::factory()->unverified()->create();

    expect($verified->isVerified())->toBeTrue()
        ->and($verified->isClaimed())->toBeFalse()
        ->and($unverified->isVerified())->toBeFalse()
        ->and($unverified->isClaimed())->toBeFalse();
});

it('clears ownership claims when releasing characters belonging to deleted accounts', function () {
    $deletedUser = User::factory()->create([
        'name' => 'Deleted User #123',
        'email' => '123@deleted.fullparty.local',
    ]);
    $released = Character::factory()->create(['user_id' => $deletedUser->id, 'claimed_at' => now()]);
    $retained = Character::factory()->create(['claimed_at' => now()]);

    $this->artisan('characters:release-deleted-users')->assertSuccessful();

    expect($released->fresh()->claimed_at)->toBeNull()
        ->and($released->fresh()->verified_at)->toBeNull()
        ->and($released->fresh()->user_id)->toBeNull()
        ->and($retained->fresh()->isClaimed())->toBeTrue();
});
