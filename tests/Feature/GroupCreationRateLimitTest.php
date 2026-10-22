<?php

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    config([
        'quotas.mode' => 'enforce',
        'quotas.limits' => array_replace(config('quotas.limits'), ['groups.owned' => 10]),
    ]);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function groupRateLimitPayload(string $slug): array
{
    return [
        'name' => 'Test Group',
        'slug' => $slug,
        'group_type' => 'community',
        'datacenter' => 'Light',
        'join_mode' => 'open',
        'is_visible' => true,
        'primary_focuses' => ['progression'],
        'experience_expectation' => 'casual',
        'voice_expectation' => 'optional',
        'preferred_languages' => ['en'],
    ];
}

it('allows twenty validation failures and only briefly throttles further attempts', function (bool $json) {
    for ($attempt = 0; $attempt < 20; $attempt++) {
        if ($json) {
            $this->postJson(route('groups.store'), [])->assertUnprocessable()->assertJsonValidationErrors('name');
        } else {
            $this->from(route('groups.create'))->post(route('groups.store'), [], ['X-Inertia' => 'true'])
                ->assertRedirect(route('groups.create'))->assertSessionHasErrors('name');
        }
    }

    $this->post(route('groups.store'), groupRateLimitPayload('retryone'))
        ->assertTooManyRequests()->assertHeader('Retry-After');
    expect(Group::count())->toBe(0);

    $this->travel(61)->seconds();
    foreach (['retryone', 'retrytwo'] as $slug) {
        $this->post(route('groups.store'), groupRateLimitPayload($slug))
            ->assertRedirect(route('groups.dashboard', $slug));
    }
    $this->post(route('groups.store'), groupRateLimitPayload('retrytri'))->assertTooManyRequests();
    expect(Group::count())->toBe(2);
})->with(['Inertia validation redirects' => false, 'JSON validation errors' => true]);

it('does not count invalid retries as successful creations even after a success flash', function () {
    $this->post(route('groups.store'), groupRateLimitPayload('firstgrp'))
        ->assertRedirect(route('groups.dashboard', 'firstgrp'));

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->from(route('groups.dashboard', 'firstgrp'))
            ->post(route('groups.store'), groupRateLimitPayload('firstgrp'))
            ->assertRedirect(route('groups.dashboard', 'firstgrp'))
            ->assertSessionHasErrors('slug');
    }

    $this->post(route('groups.store'), groupRateLimitPayload('othergrp'))
        ->assertRedirect(route('groups.dashboard', 'othergrp'));
    $this->post(route('groups.store'), groupRateLimitPayload('thirdgrp'))->assertTooManyRequests();
    expect(Group::count())->toBe(2);
});

it('still limits successful creations to five per day and resets after the window', function () {
    foreach (['groupa', 'groupb', 'groupc', 'groupd', 'groupe'] as $slug) {
        $this->post(route('groups.store'), groupRateLimitPayload($slug))
            ->assertRedirect(route('groups.dashboard', $slug));
        $this->travel(61)->minutes();
    }
    $this->post(route('groups.store'), groupRateLimitPayload('groupf'))
        ->assertTooManyRequests()->assertHeader('Retry-After');
    expect(Group::count())->toBe(5);

    $this->travel(1)->day();
    $this->post(route('groups.store'), groupRateLimitPayload('groupf'))
        ->assertRedirect(route('groups.dashboard', 'groupf'));
    expect(Group::count())->toBe(6);
});

it('keeps the retry allowance separate for each account', function () {
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $this->postJson(route('groups.store'), [])->assertUnprocessable();
    }
    $this->postJson(route('groups.store'), [])->assertTooManyRequests();

    $this->actingAs(User::factory()->create())
        ->post(route('groups.store'), groupRateLimitPayload('newuser'))
        ->assertRedirect(route('groups.dashboard', 'newuser'));
});
