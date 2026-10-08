<?php

use App\DTOs\QuotaCheck;
use App\Jobs\SendDiscordAdminReportJob;
use App\Models\Activity;
use App\Models\ContentReport;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceLibrary;
use App\Models\ModerationCase;
use App\Models\QuotaOverride;
use App\Models\User;
use App\Services\Moderation\ReportSubmissionService;
use App\Services\Quotas\QuotaService;
use App\Services\RichText\RichTextDocument;
use App\Support\Quotas\QuotaKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.admin_reports.enabled', true);
    config()->set('app.locale', 'en');
    config()->set('app.url', 'https://fullparty.test');
    config()->set('quotas.mode', 'observe');
    // Quota keys contain dots and are literal array keys.
    config()->set('quotas.limits', [QuotaKey::GROUPS_OWNED => 1, QuotaKey::GROUPS_JOINED => 1, QuotaKey::FUTURE_RUNS => 1]);
    Cache::flush();
    $this->realQueue = Queue::getFacadeRoot();
    Queue::fake();
    Http::preventStrayRequests();
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->group = Group::factory()->create(['owner_id' => $this->user->id, 'name' => '@everyone Private title']);
    $this->payload = ['target_type' => 'group', 'target_id' => $this->group->id, 'reason' => 'other', 'details' => 'PRIVATE report details'];
});

it('alerts for each saved report but not duplicate retries or raw user content', function () {
    $reports = app(ReportSubmissionService::class);
    $reports->submit($this->user, $this->payload);
    $reports->submit($this->user, $this->payload);
    Queue::assertPushed(SendDiscordAdminReportJob::class, 1);
    $case = ModerationCase::sole();
    $url = 'https://fullparty.test'.route('admin.reports.show', ['case' => $case->id, 'locale' => 'en'], false);
    Queue::assertPushed(SendDiscordAdminReportJob::class, fn ($job) => $job->title === 'New content report'
        && $job->severity === 'warning' && $job->afterCommit
        && str_contains($job->message, 'Account #'.$this->user->id)
        && str_contains($job->message, 'Group #'.$this->group->id)
        && str_contains($job->message, $url)
        && ! str_contains($job->message, 'PRIVATE') && ! str_contains($job->message, '@everyone'));
    $reports->submit(User::factory()->create(), $this->payload);
    Queue::assertPushed(SendDiscordAdminReportJob::class, 2);
    expect(ContentReport::count())->toBe(2)->and(ModerationCase::count())->toBe(1);
});

it('waits for a committed report and does not consume its alert cooldown after a rollback', function () {
    DB::beginTransaction();
    app(ReportSubmissionService::class)->submit($this->user, $this->payload);
    Queue::assertNotPushed(SendDiscordAdminReportJob::class);
    DB::rollBack();
    Queue::assertNotPushed(SendDiscordAdminReportJob::class);
    expect(ContentReport::count())->toBe(0);
    app(ReportSubmissionService::class)->submit($this->user, $this->payload);
    Queue::assertPushed(SendDiscordAdminReportJob::class, 1);
});

it('does not send alerts for rejected reports', function () {
    $this->group->update(['is_visible' => false]);
    $this->actingAs(User::factory()->create())->postJson(route('reports.store'), $this->payload)->assertNotFound();
    $this->postJson(route('reports.store'), [...$this->payload, 'reason' => 'invalid'])->assertUnprocessable();
    Queue::assertNotPushed(SendDiscordAdminReportJob::class);
});

it('sends guest report alerts with main-site review links and no network identifiers', function () {
    $this->group->features()->update(['resource_hub_enabled' => true]);
    GroupResourceLibrary::create(['group_id' => $this->group->id, 'visibility' => 'public']);
    $resource = GroupResource::create(['group_id' => $this->group->id, 'slug' => 'guide', 'status' => 'published', 'access_level' => 'everyone']);
    $revision = $resource->revisions()->create(['editor_user_id' => $this->user->id, 'editor' => ['name' => 'Editor'], 'summary' => 'Initial',
        'snapshot' => ['title' => 'Guide', 'description' => 'Public guide', 'body' => RichTextDocument::empty(), 'image_ids' => []]]);
    $resource->update(['published_revision_id' => $revision->id]);
    $payload = ['target_type' => 'resource', 'target_id' => $resource->id, 'reason' => 'spam'];
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.12']);
    $this->postJson(route('public-resources.reports.store', $this->group), $payload)->assertCreated();
    $this->postJson(route('public-resources.reports.store', $this->group), $payload)->assertCreated();
    $report = ContentReport::sole();
    Queue::assertPushed(SendDiscordAdminReportJob::class, 1);
    Queue::assertPushed(SendDiscordAdminReportJob::class, fn ($job) => str_contains($job->message, 'A guest')
        && str_contains($job->message, 'Spam or unwanted advertising')
        && str_contains($job->message, 'https://fullparty.test/en/admin/reports/')
        && ! str_contains($job->message, 'resources.fullparty.test')
        && ! str_contains($job->message, '203.0.113.12') && ! str_contains($job->message, $report->guest_fingerprint));
});

it('alerts in observe mode without blocking the operation', function () {
    $created = app(QuotaService::class)->run([new QuotaCheck(QuotaKey::GROUPS_OWNED, $this->user)], fn () => Group::factory()->create(['owner_id' => $this->user->id]));
    expect($created->exists)->toBeTrue();
    Queue::assertNotPushed(SendDiscordAdminReportJob::class);
    defer()->invoke();
    Queue::assertPushed(SendDiscordAdminReportJob::class, 1);
    Queue::assertPushed(SendDiscordAdminReportJob::class, fn ($job) => $job->severity === 'warning'
        && str_contains($job->message, 'Observe mode') && str_contains($job->message, 'Usage: 1; attempted addition: 1; limit: 1')
        && str_contains($job->message, 'Account #'.$this->user->id) && str_contains($job->message, 'Groups owned')
        && str_contains($job->message, 'https://fullparty.test/en/admin/quotas'));
});

it('persists a real database queue job after an enforced request rolls back with validation errors', function () {
    config()->set('quotas.mode', 'enforce');
    config()->set('queue.default', 'database');
    Queue::swap($this->realQueue);
    $other = Group::factory()->open()->create();
    $this->actingAs($this->user)->postJson(route('groups.join', $other))->assertUnprocessable()->assertJsonValidationErrors('quota');
    expect($other->memberships()->where('user_id', $this->user->id)->exists())->toBeFalse();
    expect(DB::table('jobs')->where('payload', 'like', '%SendDiscordAdminReportJob%')->count())->toBe(1);
    $payload = DB::table('jobs')->where('payload', 'like', '%SendDiscordAdminReportJob%')->value('payload');
    expect($payload)->toContain('Enforce mode')->toContain('Groups joined');
    Http::assertNothingSent();
});

it('preserves quota alerts when an outer transaction also rolls back', function () {
    config()->set('quotas.mode', 'enforce');
    expect(fn () => DB::transaction(fn () => app(QuotaService::class)->run(
        [new QuotaCheck(QuotaKey::GROUPS_OWNED, $this->user)], fn () => null,
    )))->toThrow(ValidationException::class);
    expect(defer()->first()->always)->toBeTrue();
    defer()->invoke();
    Queue::assertPushed(SendDiscordAdminReportJob::class, 1);
});

it('deduplicates repeated quota alerts for fifteen minutes without hiding other accounts or modes', function () {
    $check = fn ($user) => app(QuotaService::class)->assert(new QuotaCheck(QuotaKey::GROUPS_OWNED, $user));
    $check($this->user);
    defer()->invoke();
    $check($this->user);
    defer()->invoke();
    Queue::assertPushed(SendDiscordAdminReportJob::class, 1);
    $other = User::factory()->create();
    Group::factory()->create(['owner_id' => $other->id]);
    $check($other);
    defer()->invoke();
    Queue::assertPushed(SendDiscordAdminReportJob::class, 2);
    config()->set('quotas.mode', 'enforce');
    expect(fn () => $check($this->user))->toThrow(ValidationException::class);
    defer()->invoke();
    Queue::assertPushed(SendDiscordAdminReportJob::class, 3);
    $this->travel(16)->minutes();
    expect(fn () => $check($this->user))->toThrow(ValidationException::class);
    defer()->invoke();
    Queue::assertPushed(SendDiscordAdminReportJob::class, 4);
});

it('also reports limits scoped to a group', function () {
    Activity::factory()->create(['group_id' => $this->group->id, 'starts_at' => now()->addDay(), 'status' => Activity::STATUS_SCHEDULED]);
    app(QuotaService::class)->assert(new QuotaCheck(QuotaKey::FUTURE_RUNS, $this->group));
    defer()->invoke();
    Queue::assertPushed(SendDiscordAdminReportJob::class, fn ($job) => str_contains($job->message, 'Group #'.$this->group->id) && str_contains($job->message, 'Upcoming runs'));
});

it('keeps status reads, allowed writes, overrides and idempotent operations quiet', function () {
    $service = app(QuotaService::class);
    $check = new QuotaCheck(QuotaKey::GROUPS_OWNED, $this->user);
    $service->status($check->key, $this->user, amount: 1);
    $service->runIf([$check], fn () => false, fn () => null);
    $service->assert(new QuotaCheck(QuotaKey::GROUPS_OWNED, User::factory()->create()));
    $override = QuotaOverride::create(['subject_type' => 'user', 'subject_id' => $this->user->id, 'quota_key' => QuotaKey::GROUPS_OWNED, 'limit' => 2, 'is_unlimited' => false, 'reason' => 'Approved', 'created_by_user_id' => $this->user->id]);
    $service->assert($check);
    $override->update(['is_unlimited' => true]);
    $service->assert($check);
    defer()->invoke();
    Queue::assertNotPushed(SendDiscordAdminReportJob::class);
});

it('keeps workflows working when admin alert queueing fails', function () {
    Bus::shouldReceive('dispatch')->twice()->andThrow(new RuntimeException('Queue unavailable'));
    app(ReportSubmissionService::class)->submit($this->user, $this->payload);
    expect(ContentReport::count())->toBe(1);
    $result = app(QuotaService::class)->run([new QuotaCheck(QuotaKey::GROUPS_OWNED, $this->user)], fn () => 'completed');
    defer()->invoke();
    expect($result)->toBe('completed');
});

it('respects the global admin report switch', function () {
    config()->set('services.admin_reports.enabled', false);
    app(ReportSubmissionService::class)->submit($this->user, $this->payload);
    app(QuotaService::class)->assert(new QuotaCheck(QuotaKey::GROUPS_OWNED, $this->user));
    defer()->invoke();
    expect(ContentReport::count())->toBe(1);
    Queue::assertNotPushed(SendDiscordAdminReportJob::class);
});

it('renders the new off-site messages in every configured language', function (string $locale) {
    config()->set('app.locale', $locale);
    app(ReportSubmissionService::class)->submit($this->user, $this->payload);
    app(QuotaService::class)->assert(new QuotaCheck(QuotaKey::GROUPS_OWNED, $this->user));
    defer()->invoke();
    Queue::assertPushed(SendDiscordAdminReportJob::class, 2);
    foreach (Queue::pushed(SendDiscordAdminReportJob::class) as $job) {
        expect($job->title.$job->message)->not->toContain('admin_reports.', 'reports.types.', 'reports.reasons.', 'quotas.labels.', ':subject_id', ':target_id', ':quota', ':reporter');
        expect($job->message)->toContain('https://fullparty.test/'.$locale.'/admin/');
    }
})->with(['en', 'de', 'fr', 'ja']);
