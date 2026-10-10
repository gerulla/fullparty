<?php

use App\Jobs\SendDiscordGuildRunsChangedJob;
use App\Models\Activity;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\DiscordGuildIntegration;
use App\Models\Group;
use App\Models\IntegrationClient;
use App\Models\User;
use App\Services\Groups\ActivityDuplicationService;
use App\Services\Integrations\DiscordGuildRunsChangedService;
use App\Services\Integrations\IntegrationWebhookDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\OpenApiContract;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->realQueue = Queue::getFacadeRoot();
    Queue::fake();
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->group = Group::factory()->open()->create(['owner_id' => $this->owner->id]);
    $this->character = Character::factory()->primary()->create(['user_id' => $this->owner->id]);
    $this->version = ActivityTypeVersion::factory()->create([
        'layout_schema' => ['groups' => []], 'slot_schema' => [], 'application_schema' => [],
        'progress_schema' => ['milestones' => []], 'prog_points' => [], 'bench_size' => 0,
    ]);
    $this->version->activityType->update(['is_active' => true, 'current_published_version_id' => $this->version->id]);
    $this->guild = DiscordGuildIntegration::create([
        'group_id' => $this->group->id, 'discord_guild_id' => '123456789012345678', 'guild_installed_at' => now(),
    ]);
    $this->makeActivity = fn (array $attributes = []) => Activity::factory()->create([
        'group_id' => $this->group->id, 'activity_type_id' => $this->version->activity_type_id,
        'activity_type_version_id' => $this->version->id, 'organized_by_user_id' => $this->owner->id,
        'organized_by_character_id' => $this->character->id, 'status' => Activity::STATUS_SCHEDULED,
        'allow_guest_applications' => false,
        ...$attributes,
    ]);
    $this->actingAs($this->owner);
});

it('queues visible run creation but excludes drafts for public and members-only runs', function (string $status, bool $public) {
    $this->post(route('groups.dashboard.activities.store', $this->group), [
        'activity_type_id' => $this->version->activity_type_id,
        'organized_by_user_id' => $this->owner->id, 'organized_by_character_id' => $this->character->id,
        'status' => $status, 'title' => 'New run', 'is_public' => $public,
        'starts_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
    ])->assertSessionHasNoErrors()->assertRedirect();

    Queue::assertPushed(SendDiscordGuildRunsChangedJob::class, $status === Activity::STATUS_DRAFT ? 0 : 1);
    Http::assertNothingSent();
})->with([Activity::STATUS_DRAFT, Activity::STATUS_SCHEDULED])->with([true, false]);

it('queues edits only for visible runs including members-only visibility changes', function (string $status) {
    $activity = ($this->makeActivity)(['status' => $status]);
    $this->put(route('groups.dashboard.activities.update', [$this->group, $activity]), [
        'title' => 'Changed run', 'is_public' => false,
        'starts_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect($activity->fresh()->title)->toBe('Changed run');
    Queue::assertPushed(SendDiscordGuildRunsChangedJob::class, $status === Activity::STATUS_DRAFT ? 0 : 1);
})->with([Activity::STATUS_DRAFT, Activity::STATUS_SCHEDULED, Activity::STATUS_ASSIGNED]);

it('does not send refreshes for unchanged saves or failed validation', function () {
    $activity = ($this->makeActivity)(['is_public' => true]);
    $url = route('groups.dashboard.activities.update', [$this->group, $activity]);
    $this->put($url, ['title' => $activity->title])->assertSessionHasNoErrors();
    Queue::assertNotPushed(SendDiscordGuildRunsChangedJob::class);
    $this->putJson($url, ['title' => str_repeat('x', 256)])->assertUnprocessable();
    Queue::assertNotPushed(SendDiscordGuildRunsChangedJob::class);
});

it('queues publication and roster publication after their status changes', function (string $action, string $before, string $after) {
    $activity = ($this->makeActivity)(['status' => $before]);
    $this->post(route('groups.dashboard.activities.'.$action, [$this->group, $activity]))
        ->assertSessionHasNoErrors()->assertRedirect();
    expect($activity->fresh()->status)->toBe($after);
    Queue::assertPushed(SendDiscordGuildRunsChangedJob::class, 1);
})->with([
    ['schedule', Activity::STATUS_DRAFT, Activity::STATUS_SCHEDULED],
    ['publish-roster', Activity::STATUS_SCHEDULED, Activity::STATUS_ASSIGNED],
]);

it('queues cancellation of a visible run', function () {
    $activity = ($this->makeActivity)(['status' => Activity::STATUS_ASSIGNED]);
    $this->post(route('groups.dashboard.activities.cancel', [$this->group, $activity]), ['reason' => 'Rescheduling'])
        ->assertSessionHasNoErrors()->assertRedirect();
    expect($activity->fresh()->status)->toBe(Activity::STATUS_CANCELLED);
    Queue::assertPushed(SendDiscordGuildRunsChangedJob::class, 1);
});

it('queues completion so the bot can remove the run from its upcoming list', function () {
    $activity = ($this->makeActivity)(['status' => Activity::STATUS_ASSIGNED]);
    $this->postJson(route('groups.dashboard.activities.complete', [$this->group, $activity]), [])->assertOk();
    expect($activity->fresh()->status)->toBe(Activity::STATUS_COMPLETE);
    Queue::assertPushed(SendDiscordGuildRunsChangedJob::class, 1);
});

it('queues deletion only for a visible run', function (string $status) {
    $activity = ($this->makeActivity)(['status' => $status]);
    $this->delete(route('groups.dashboard.activities.destroy', [$this->group, $activity]))
        ->assertSessionHasNoErrors()->assertRedirect();
    Queue::assertPushed(SendDiscordGuildRunsChangedJob::class, $status === Activity::STATUS_DRAFT ? 0 : 1);
})->with([Activity::STATUS_DRAFT, Activity::STATUS_SCHEDULED]);

it('queues duplication only when the new run is visible', function (string $status) {
    $source = ($this->makeActivity)(['status' => Activity::STATUS_COMPLETE]);
    app(ActivityDuplicationService::class)->duplicate($source, $this->owner, 'Duplicate run', CarbonImmutable::now()->addDays(4), $status, false, false);
    Queue::assertPushed(SendDiscordGuildRunsChangedJob::class, $status === Activity::STATUS_DRAFT ? 0 : 1);
})->with([Activity::STATUS_DRAFT, Activity::STATUS_SCHEDULED]);

it('does not queue an event when no active guild is linked', function (bool $removed) {
    $this->guild->update($removed ? ['removed_at' => now()] : ['group_id' => null]);
    app(DiscordGuildRunsChangedService::class)->notifyChanged(($this->makeActivity)());
    Queue::assertNotPushed(SendDiscordGuildRunsChangedJob::class);
})->with([true, false]);

it('waits for the save transaction to commit and drops notifications on rollback', function (bool $commit) {
    config()->set('queue.default', 'database');
    Queue::swap($this->realQueue);
    $activity = ($this->makeActivity)();

    try {
        DB::transaction(function () use ($activity, $commit) {
            $activity->update(['title' => 'Transaction change']);
            app(DiscordGuildRunsChangedService::class)->notifyChanged($activity);
            expect(DB::table('jobs')->count())->toBe(0);
            if (! $commit) {
                throw new RuntimeException('Rollback test');
            }
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Rollback test');
    }

    expect(DB::table('jobs')->count())->toBe($commit ? 1 : 0);
    Http::assertNothingSent();
})->with([true, false]);

it('delivers exactly the requested guild payload with the existing signature and documented contract', function () {
    $client = IntegrationClient::factory()->create(['allowed_events' => [IntegrationClient::EVENT_DISCORD_GUILD_RUNS_CHANGED]]);
    Http::fake([$client->outbound_events_url => Http::response([], 204)]);
    $job = new SendDiscordGuildRunsChangedJob($this->guild->id, $this->group->id, $this->guild->discord_guild_id);
    $job->handle(app(IntegrationWebhookDispatcher::class));

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($client, $job) {
        $document = json_decode(file_get_contents(resource_path('openapi/fullparty.json')), true, flags: JSON_THROW_ON_ERROR);
        OpenApiContract::assertMatches(json_decode($request->body()), $document['webhooks']['discord.guild.runs_changed']['post']['requestBody']['content']['application/json']['schema'], $document);
        $timestamp = $request->header('X-FullParty-Timestamp')[0];
        expect($request['event'])->toBe('discord.guild.runs_changed')
            ->and($request['data'])->toBe(['discord_guild_id' => '123456789012345678'])
            ->and($request['id'])->toBe($job->deliveryId)
            ->and($request->header('X-FullParty-Event')[0])->toBe('discord.guild.runs_changed')
            ->and($request->header('X-FullParty-Signature')[0])->toBe('sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), $client->webhook_signing_secret));

        return $request->method() === 'POST';
    });
});

it('retries failed delivery with the same delivery ID', function () {
    $client = IntegrationClient::factory()->create(['allowed_events' => [IntegrationClient::EVENT_DISCORD_GUILD_RUNS_CHANGED]]);
    Http::fake([$client->outbound_events_url => Http::sequence()->push([], 503)->push([], 503)->push([], 204)]);
    $job = new SendDiscordGuildRunsChangedJob($this->guild->id, $this->group->id, $this->guild->discord_guild_id);
    expect(fn () => $job->handle(app(IntegrationWebhookDispatcher::class)))->toThrow(RuntimeException::class, 'Discord guild runs-changed delivery failed.');
    $job->handle(app(IntegrationWebhookDispatcher::class));
    Http::assertSentCount(3);
    foreach (Http::recorded() as [$request]) {
        expect($request['id'])->toBe($job->deliveryId);
    }
});

it('skips delivery if the guild was unlinked while the job was queued', function () {
    IntegrationClient::factory()->create();
    $job = new SendDiscordGuildRunsChangedJob($this->guild->id, $this->group->id, $this->guild->discord_guild_id);
    $this->guild->update(['group_id' => null]);
    $job->handle(app(IntegrationWebhookDispatcher::class));
    Http::assertNothingSent();
});

it('respects the event capability and paused integration clients', function (bool $paused) {
    IntegrationClient::factory()->create([
        'status' => $paused ? IntegrationClient::STATUS_PAUSED : IntegrationClient::STATUS_ACTIVE,
        'allowed_events' => $paused ? [IntegrationClient::EVENT_DISCORD_GUILD_RUNS_CHANGED] : [],
    ]);
    (new SendDiscordGuildRunsChangedJob($this->guild->id, $this->group->id, $this->guild->discord_guild_id))
        ->handle(app(IntegrationWebhookDispatcher::class));
    Http::assertNothingSent();
})->with([true, false]);

it('adds the event to existing Runs capabilities while preserving other permission choices', function () {
    $migration = require database_path('migrations/2026_10_10_000002_enable_discord_runs_changed_event.php');
    $runsClient = IntegrationClient::factory()->create(['allowed_events' => ['discord.guild.run_cancelled', 'discord.admin.report']]);
    $otherClient = IntegrationClient::factory()->create(['allowed_events' => ['discord.admin.report']]);
    $migration->up();
    $migration->up();
    expect($runsClient->fresh()->allowed_events)->toBe(['discord.guild.run_cancelled', 'discord.admin.report', 'discord.guild.runs_changed'])
        ->and($otherClient->fresh()->allowed_events)->toBe(['discord.admin.report']);
    $migration->down();
    expect($runsClient->fresh()->allowed_events)->toBe(['discord.guild.run_cancelled', 'discord.admin.report']);
});
