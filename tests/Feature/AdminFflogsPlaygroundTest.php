<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('guards the ff logs playground to admins', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('admin.fflogs-playground.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson(route('admin.fflogs-playground.execute'), [
            'request' => 'query { worldData { zones { id } } }',
        ])
        ->assertForbidden();
});

it('renders the ff logs playground for admins', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.fflogs-playground.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/FflogsPlayground'));
});

it('lets admins send a manual ff logs graphql payload', function () {
    config()->set('services.ff_logs.client_id', 'client-id');
    config()->set('services.ff_logs.client_secret', 'client-secret');
    config()->set('services.ff_logs.token_url', 'https://fflogs.test/oauth/token');
    config()->set('services.ff_logs.graphql_url', 'https://fflogs.test/graphql');

    Cache::forget('fflogs:client_credentials_token');

    $admin = User::factory()->create(['is_admin' => true]);

    Http::fake([
        'https://fflogs.test/oauth/token' => Http::response([
            'access_token' => 'token',
            'expires_in' => 3600,
        ]),
        'https://fflogs.test/graphql' => Http::response([
            'data' => [
                'reportData' => [
                    'report' => [
                        'title' => 'Test Report',
                    ],
                ],
            ],
        ]),
    ]);

    $response = $this->actingAs($admin)
        ->postJson(route('admin.fflogs-playground.execute'), [
            'request' => json_encode([
                'query' => 'query ReportSummary($code: String!) { reportData { report(code: $code) { title } } }',
                'variables' => [
                    'code' => 'abc123',
                ],
            ]),
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('request.endpoint', 'https://fflogs.test/graphql')
        ->assertJsonPath('request.payload.variables.code', 'abc123')
        ->assertJsonPath('response.ok', true)
        ->assertJsonPath('response.status', 200)
        ->assertJsonPath('response.body.data.reportData.report.title', 'Test Report');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://fflogs.test/oauth/token');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://fflogs.test/graphql'
        && $request->hasHeader('Authorization', 'Bearer token')
        && $request['variables']['code'] === 'abc123');
});
