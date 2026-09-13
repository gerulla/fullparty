<?php

use App\Events\UserNotificationsUpdated;
use App\Models\BozjaHolster;
use App\Models\ContentReport;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceImage;
use App\Models\GroupResourceLibrary;
use App\Models\ModerationCase;
use App\Models\ReportFeedbackRecipient;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Moderation\GuestReportIdentity;
use App\Services\RichText\RichTextDocument;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function guest_reporting_app_url(string $name, mixed $parameters = []): string
{
    return rtrim(config('app.url'), '/').route($name, $parameters, false);
}

beforeEach(function () {
    Event::fake([UserNotificationsUpdated::class]);
    Storage::fake('local');
    $this->group = Group::factory()->create();
    $this->group->features()->update(['resource_hub_enabled' => true]);
    $this->library = GroupResourceLibrary::create(['group_id' => $this->group->id, 'visibility' => 'public']);
    $this->resource = GroupResource::create(['group_id' => $this->group->id, 'slug' => 'public-guide', 'status' => 'published', 'access_level' => 'everyone']);
    $revision = $this->resource->revisions()->create(['editor_user_id' => $this->group->owner_id, 'editor' => ['name' => 'Editor'], 'summary' => 'Initial guide',
        'snapshot' => ['title' => 'Public guide', 'description' => 'Public content', 'body' => RichTextDocument::empty(), 'image_ids' => []],
    ]);
    $this->resource->update(['published_revision_id' => $revision->id]);
    $this->url = route('public-resources.reports.store', $this->group);
    $this->payload = ['target_type' => 'resource', 'target_id' => $this->resource->id, 'reason' => 'spam'];
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
});

it('accepts a guest report on the public host without disclosing case or identity information', function () {
    $this->postJson($this->url, $this->payload)->assertCreated()->assertExactJson(['message' => __('reports.guest_thanks')]);
    $report = ContentReport::sole();
    expect($report->reporter_id)->toBeNull()->and($report->guest_fingerprint)->toHaveLength(64)
        ->and($report->toJson())->not->toContain('203.0.113.10', 'guest_fingerprint')
        ->and($report->snapshot['title'])->toBe('Public guide');
    $this->postJson(guest_reporting_app_url('reports.store'), $this->payload)->assertUnauthorized();
    $this->postJson(rtrim(config('app.url'), '/').'/'.$this->group->slug.'/reports', $this->payload)->assertNotFound();
});

it('provides an in-place guest reporting endpoint on public resource pages', function () {
    $this->get(route('public-resources.show', [$this->group, $this->resource->uuid]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Resources/Show')
            ->where('reporting.guest_submit_url', route('public-resources.reports.store', $this->group, false))->missing('auth'));
});

it('deduplicates a network on the same open case while retaining reports from other networks', function () {
    $this->postJson($this->url, $this->payload)->assertCreated();
    $case = ModerationCase::sole();
    $this->postJson($this->url, $this->payload + ['guest_fingerprint' => str_repeat('a', 64)])->assertCreated();
    expect($case->fresh()->version)->toBe(1)->and($case->reports()->count())->toBe(1);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])->postJson($this->url, $this->payload)->assertCreated();
    expect($case->reports()->count())->toBe(2)->and($case->fresh()->version)->toBe(2);
});

it('enforces per-minute limits across targets and ignores spoofed forwarding headers', function () {
    for ($i = 0; $i < 3; $i++) {
        $this->withHeader('X-Forwarded-For', '198.51.100.'.($i + 1))->postJson($this->url, $this->payload)->assertCreated();
    }
    $this->withHeader('X-Forwarded-For', '198.51.100.99')->postJson($this->url, array_replace($this->payload, ['target_id' => 999999]))
        ->assertTooManyRequests()->assertHeader('Retry-After');
    expect(ContentReport::count())->toBe(1);
});

it('enforces the daily limit even after the short limit expires', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson($this->url, $this->payload)->assertCreated();
        $this->travel(61)->seconds();
    }
    $this->postJson($this->url, $this->payload)->assertTooManyRequests();
    $this->travel(1)->day();
    $this->postJson($this->url, $this->payload)->assertCreated();
});

it('normalizes IPv6 privacy addresses and IPv4-mapped addresses into stable keyed identifiers', function () {
    $fingerprint = fn ($ip) => app(GuestReportIdentity::class)->fingerprint(Request::create('/', server: ['REMOTE_ADDR' => $ip]));
    expect($fingerprint('2001:db8:1:2::1'))->toBe($fingerprint('2001:db8:1:2:abcd::99'))
        ->not->toBe($fingerprint('2001:db8:1:3::1'))
        ->and($fingerprint('::ffff:203.0.113.10'))->toBe($fingerprint('203.0.113.10'));
});

it('rejects unpublished, restricted, hidden, disabled or private public resources', function ($state) {
    match ($state) {
        'draft' => $this->resource->update(['status' => 'draft']),
        'restricted' => $this->resource->update(['access_level' => 'moderator']),
        'hidden' => $this->resource->forceFill(['moderation_hidden_at' => now()])->save(),
        'disabled' => $this->group->features()->update(['resource_hub_enabled' => false]),
        'private' => $this->library->update(['visibility' => 'private']),
        'no_revision' => $this->resource->update(['published_revision_id' => null]),
    };
    $this->postJson($this->url, $this->payload)->assertNotFound();
    expect(ContentReport::count())->toBe(0);
})->with(['draft', 'restricted', 'hidden', 'disabled', 'private', 'no_revision']);

it('rejects other groups and non-resource targets and validates Other details', function () {
    $this->postJson(route('public-resources.reports.store', Group::factory()->create()), $this->payload)->assertNotFound();
    $this->postJson($this->url, ['target_type' => 'profile', 'target_id' => $this->group->owner_id, 'reason' => 'spam'])->assertUnprocessable();
    $this->postJson($this->url, array_replace($this->payload, ['reason' => 'other', 'details' => '   ']))->assertUnprocessable();
    expect(ContentReport::count())->toBe(0);
});

it('allows public holsters but rejects inactive or unlisted holsters', function () {
    $folder = GroupResourceCollection::create(['group_id' => $this->group->id, 'name' => 'Holsters', 'slug' => 'holsters']);
    $this->library->update(['holster_collection_id' => $folder->id]);
    $holster = BozjaHolster::create(['group_id' => $this->group->id, 'name' => ['en' => 'Public holster'], 'role' => 'tank']);
    $this->resource->update(['holster_id' => $holster->id]);
    $data = ['target_type' => 'holster', 'target_id' => $holster->id, 'reason' => 'spam'];
    $this->postJson($this->url, $data)->assertCreated();
    $holster->update(['is_active' => false]);
    $this->postJson($this->url, $data)->assertNotFound();
    $holster->update(['is_active' => true]);
    $this->library->update(['holster_collection_id' => null]);
    $this->postJson($this->url, $data)->assertNotFound();
});

it('retains guest upload evidence and refuses images that are not publicly readable', function () {
    $uuid = (string) Str::uuid();
    Storage::disk('local')->put('guest-evidence.png', 'image bytes');
    $image = GroupResourceImage::create(['uuid' => $uuid, 'group_id' => $this->group->id, 'path' => 'guest-evidence.png',
        'original_name' => 'cover.png', 'mime_type' => 'image/png', 'width' => 1, 'height' => 1, 'size_bytes' => 11]);
    $data = ['target_type' => 'upload', 'target_id' => $image->id, 'reason' => 'spam'];
    $this->postJson($this->url, $data)->assertNotFound();
    $revision = $this->resource->publishedRevision;
    $revision->update(['snapshot' => array_replace($revision->snapshot, ['image_ids' => [$uuid]])]);
    $this->postJson($this->url, $data)->assertCreated();
    expect(Storage::disk('local')->get(ContentReport::sole()->evidence_path))->toBe('image bytes');
    $image->forceFill(['moderation_hidden_at' => now()])->save();
    $this->postJson($this->url, $data)->assertNotFound();
});

it('shows anonymous reporters distinctly and resolves guest-only cases without notifications', function () {
    $this->postJson($this->url, $this->payload)->assertCreated();
    $case = ModerationCase::sole();
    $admin = User::factory()->create(['is_admin' => true]);
    $response = $this->actingAs($admin)->getJson(guest_reporting_app_url('admin.reports.show', $case))->assertOk()
        ->assertJsonPath('case.reports.0.reporter', null)->assertJsonMissingPath('case.reports.0.guest_fingerprint');
    expect($response->json('case.reports.0.guest_reference'))->toHaveLength(12);
    foreach (['claim', 'dismiss'] as $action) {
        $this->postJson(guest_reporting_app_url('admin.reports.action', $case), ['action' => $action, 'version' => $case->fresh()->version, 'reason' => 'Reviewed'])->assertOk();
    }
    $this->postJson(guest_reporting_app_url('admin.reports.feedback', $case), ['version' => $case->fresh()->version, 'template' => 'no_violation'])->assertOk();
    expect($case->fresh()->status)->toBe('resolved')->and(ReportFeedbackRecipient::count())->toBe(0)->and(UserNotification::count())->toBe(0);
});

it('notifies registered reporters when a case also contains guest reports', function () {
    $this->postJson($this->url, $this->payload)->assertCreated();
    $reporter = User::factory()->create();
    $this->actingAs($reporter)->postJson(guest_reporting_app_url('reports.store'), $this->payload)->assertCreated();
    $case = ModerationCase::sole();
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    foreach (['claim', 'dismiss'] as $action) {
        $this->postJson(guest_reporting_app_url('admin.reports.action', $case), ['action' => $action, 'version' => $case->fresh()->version, 'reason' => 'Reviewed'])->assertOk();
    }
    $this->postJson(guest_reporting_app_url('admin.reports.feedback', $case), ['version' => $case->fresh()->version, 'template' => 'no_violation'])->assertOk();
    expect(ReportFeedbackRecipient::sole()->user_id)->toBe($reporter->id)->and(UserNotification::count())->toBe(1);
});

it('keeps request-forgery protection on guest submissions', function () {
    $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
    $this->postJson($this->url, $this->payload)->assertStatus(419);
    $token = Str::random(40);
    $this->withSession(['_token' => $token])->withHeader('X-CSRF-TOKEN', $token)->postJson($this->url, $this->payload)->assertCreated();
});
