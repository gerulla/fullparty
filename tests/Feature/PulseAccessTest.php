<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forbids non admin users from accessing the pulse dashboard outside local environments', function () {
    config()->set('app.env', 'production');

    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $this->actingAs($user)
        ->get('/pulse')
        ->assertForbidden();
});

it('allows admin users to access the pulse dashboard outside local environments', function () {
    config()->set('app.env', 'production');

    $user = User::factory()->create([
        'is_admin' => true,
    ]);

    $this->actingAs($user)
        ->get('/pulse')
        ->assertOk();
});
