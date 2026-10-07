<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

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

it('preserves upstream errors inside a successful diagnostic response', function (int $status, array $body) {
    config()->set('services.ff_logs.graphql_url', 'https://fflogs.test/graphql');
    Cache::put('fflogs:client_credentials_token', 'cached-token');
    Http::fake(['https://fflogs.test/graphql' => Http::response($body, $status)]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->postJson(route('admin.fflogs-playground.execute'), [
            'request' => '{ rateLimitData { limitPerHour pointsSpentThisHour pointsResetIn } }',
        ])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('response.ok', false)
        ->assertJsonPath('response.status', $status)
        ->assertJsonPath('response.body', $body)
        ->assertDontSee('cached-token');

    Http::assertSentCount(1);
})->with([
    'authentication rejected' => [401, ['error' => 'Unauthenticated.']],
    'rate limit exceeded' => [429, ['error' => 'Too many requests.']],
    'upstream service failure' => [500, ['error' => 'Internal server error.']],
]);

it('preserves the status when requesting an access token fails', function () {
    config()->set('services.ff_logs.client_id', 'client-id');
    config()->set('services.ff_logs.client_secret', 'client-secret');
    config()->set('services.ff_logs.token_url', 'https://fflogs.test/oauth/token');
    Cache::forget('fflogs:client_credentials_token');
    Http::fake(['https://fflogs.test/oauth/token' => Http::response(['error' => 'invalid_client'], 401)]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->postJson(route('admin.fflogs-playground.execute'), ['request' => '{ rateLimitData { limitPerHour } }'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('response.ok', false)
        ->assertJsonPath('response.status', 401)
        ->assertJsonPath('response.body.message', __('errors.unable_to_execute_ff_logs_request'))
        ->assertJsonPath('response.body.detail', fn (string $detail): bool => str_contains($detail, 'invalid_client'))
        ->assertDontSee('client-secret');

    Http::assertSentCount(1);
});

it('keeps connection failures readable without issuing a gateway response', function () {
    config()->set('services.ff_logs.graphql_url', 'https://fflogs.test/graphql');
    Cache::put('fflogs:client_credentials_token', 'cached-token');
    Http::fake(['https://fflogs.test/graphql' => Http::failedConnection('Connection timed out')]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->postJson(route('admin.fflogs-playground.execute'), ['request' => '{ rateLimitData { limitPerHour } }'])
        ->assertOk()
        ->assertJsonPath('response.ok', false)
        ->assertJsonPath('response.status', null)
        ->assertJsonPath('response.body.detail', fn (string $detail): bool => str_contains($detail, 'Connection timed out'));
});
