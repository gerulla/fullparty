<?php

use App\Events\UserNotificationsUpdated;
use App\Models\BozjaHolster;
use App\Models\ContentReport;
use App\Models\Group;
use App\Models\GroupMembershipApplication;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceImage;
use App\Models\GroupResourceLibrary;
use App\Models\GroupUserNote;
use App\Models\ModerationCase;
use App\Models\NotificationEvent;
use App\Models\ReportFeedback;
use App\Models\ReportFeedbackRecipient;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Groups\Resources\ResourceImageService;
use App\Services\Groups\Resources\ResourceReaderService;
use App\Services\Moderation\ReportContentPreview;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Passport;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([UserNotificationsUpdated::class]);
    Storage::fake('local');
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->reporter = User::factory()->create(['system_notice_notifications' => false]);
    $this->author = User::factory()->create();
    $this->group = Group::factory()->create();
    $this->group->features()->update(['resource_hub_enabled' => true]);
    GroupResourceLibrary::create(['group_id' => $this->group->id, 'visibility' => 'public']);
    $folder = GroupResourceCollection::create(['group_id' => $this->group->id, 'name' => 'Guides', 'slug' => 'guides']);
    $this->resource = GroupResource::create(['group_id' => $this->group->id, 'collection_id' => $folder->id, 'slug' => 'reported-guide', 'status' => 'published']);
    $revision = $this->resource->revisions()->create([
        'editor_user_id' => $this->author->id, 'editor' => ['name' => $this->author->name], 'summary' => 'Initial guide',
        'snapshot' => ['title' => 'Reported guide', 'description' => 'Original description', 'body' => ['type' => 'doc', 'content' => []], 'image_ids' => []],
    ]);
    $this->resource->update(['published_revision_id' => $revision->id]);
    $this->payload = ['target_type' => 'resource', 'target_id' => $this->resource->id, 'reason' => 'spam'];
});

function moderation_report($test, ?User $user = null): ModerationCase
{
    $test->actingAs($user ?? $test->reporter)->postJson(route('reports.store'), $test->payload)->assertCreated()->assertJsonMissingPath('case');

    return ModerationCase::latest('id')->firstOrFail();
}

function moderation_action($test, ModerationCase $case, string $action, ?User $admin = null)
{
    return $test->actingAs($admin ?? $test->admin)->postJson(route('admin.reports.action', $case), [
        'version' => $case->fresh()->version, 'action' => $action, 'reason' => 'Reviewed original evidence',
    ]);
}

function moderation_resolve($test, ModerationCase $case, string $template = 'no_violation')
{
    return $test->actingAs($test->admin)->postJson(route('admin.reports.feedback', $case), [
        'version' => $case->fresh()->version, 'template' => $template,
    ]);
}

it('opens an admin review page with reporter profiles and group context while keeping JSON mutations compatible', function () {
    $this->reporter->homeProfile()->create(['description' => 'Community raider']);
    $this->reporter->characters()->create(['name' => 'Known Character', 'world' => 'Shiva', 'datacenter' => 'Light', 'lodestone_id' => '123456', 'verified_at' => now(), 'token' => 'private-character-token']);
    $this->resource->update(['author_user_id' => $this->author->id]);
    $case = moderation_report($this);
    $this->get(route('admin.reports.show', $case))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.reports.show', $case))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Reports/Show')
            ->where('detail.case.id', $case->id)
            ->where('detail.case.reports.0.reporter.id', $this->reporter->id)
            ->where('detail.case.reports.0.reporter.description', 'Community raider')
            ->where('detail.case.reports.0.reporter.characters.0.name', 'Known Character')
            ->missing('detail.case.reports.0.reporter.characters.0.token')
            ->missing('detail.case.reports.0.reporter.email')
            ->missing('detail.case.reports.0.reporter.password')
            ->where('detail.context.group.id', $this->group->id)
            ->where('detail.context.group.owner.id', $this->group->owner_id)
            ->where('detail.context.owner.id', $this->author->id)
            ->where('detail.context.collection', 'Guides'));
    $this->getJson(route('admin.reports.show', $case))->assertOk()->assertJsonPath('case.id', $case->id);
    moderation_action($this, $case, 'claim')->assertOk()->assertJsonPath('context.group.id', $this->group->id);
});

it('renders formatted current content and keeps original evidence separate after a correction', function () {
    $case = moderation_report($this);
    $revision = $this->resource->publishedRevision;
    $revision->update(['snapshot' => array_replace($revision->snapshot, [
        'description' => 'Corrected description',
        'body' => ['type' => 'doc', 'content' => [['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Updated guide']]]]],
    ])]);
    $response = $this->actingAs($this->admin)->getJson(route('admin.reports.show', $case))->assertOk()
        ->assertJsonPath('context.preview.description', 'Corrected description')
        ->assertJsonPath('case.reports.0.preview.text', 'Original description');
    expect($response->json('context.preview.html'))->toContain('<h2', 'Updated guide');
});

it('keeps deleted targets reviewable with the retained evidence and reporter', function () {
    $case = moderation_report($this);
    $this->resource->delete();
    $this->actingAs($this->admin)->get(route('admin.reports.show', $case))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Reports/Show')
            ->where('detail.target_exists', false)->where('detail.context.preview', null)
            ->where('detail.context.group', null)->where('detail.case.reports.0.reporter.id', $this->reporter->id)
            ->where('detail.case.reports.0.preview.text', 'Original description'));
});

it('serves hidden resource images only to admins and only when referenced by the reviewed target', function () {
    Storage::fake(config('group_resources.disk'));
    $makeImage = function (Group $group) {
        $uuid = (string) Str::uuid();
        Storage::disk(config('group_resources.disk'))->put($uuid.'.png', 'preview image');

        return GroupResourceImage::create(['uuid' => $uuid, 'group_id' => $group->id, 'path' => $uuid.'.png',
            'original_name' => 'cover.png', 'mime_type' => 'image/png', 'size_bytes' => 13, 'width' => 1, 'height' => 1,
            'moderation_hidden_at' => now()]);
    };
    $image = $makeImage($this->group);
    $unrelated = $makeImage($this->group);
    $otherGroupImage = $makeImage(Group::factory()->create());
    $revision = $this->resource->publishedRevision;
    $revision->update(['snapshot' => array_replace($revision->snapshot, ['image_ids' => [$image->uuid, $otherGroupImage->uuid], 'metadata_image_id' => $image->uuid,
        'body' => ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => '/resource-assets/'.$image->uuid]]]],
    ])]);
    $case = moderation_report($this);
    $this->resource->forceFill(['moderation_hidden_at' => now()])->save();
    $this->get(route('admin.reports.asset', [$case, $image]))->assertForbidden();
    $response = $this->actingAs($this->admin)->getJson(route('admin.reports.show', $case))->assertOk()
        ->assertJsonCount(1, 'context.images');
    $url = route('admin.reports.asset', [$case, $image], false);
    expect($response->json('context.preview.html'))->toContain($url);
    expect($response->json('case.reports.0.preview.html'))->not->toContain('<img');
    $this->get($url)->assertOk()->assertStreamedContent('preview image')->assertHeader('Cache-Control', 'no-store, private');
    $this->get(route('admin.reports.asset', [$case, $unrelated]))->assertNotFound();
    $this->get(route('admin.reports.asset', [$case, $otherGroupImage]))->assertNotFound();
});

it('does not render unsafe legacy document markup in the admin preview', function () {
    $preview = app(ReportContentPreview::class)->build([
        'title' => 'Unsafe markup', 'text' => '<script>alert(1)</script>',
        'content' => ['body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'unsafe', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]],
        ]]]]],
    ]);
    expect($preview['html'])->toBeNull()->and($preview['text'])->toBe('<script>alert(1)</script>');
});

it('shows the actual inherited guide when a linked holster is hidden', function () {
    $holster = BozjaHolster::create(['group_id' => $this->group->id, 'name' => ['en' => 'Tank preparation'], 'role' => 'tank',
        'guide' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Reviewable holster guide']]]]],
    ]);
    $holster->forceFill(['moderation_hidden_at' => now()])->save();
    $this->resource->update(['holster_id' => $holster->id]);
    $case = ModerationCase::create(['target_type' => 'resource', 'target_id' => $this->resource->id, 'title' => 'Tank preparation', 'status' => 'new']);
    $response = $this->actingAs($this->admin)->getJson(route('admin.reports.show', $case))->assertOk()
        ->assertJsonPath('context.preview.title', 'Tank preparation');
    expect($response->json('context.preview.html'))->toContain('Reviewable holster guide');
});

it('shows private reported profiles to admins without exposing authentication data', function () {
    $this->author->update(['public_profile' => false]);
    $this->author->homeProfile()->create(['description' => 'Profile being reviewed']);
    $case = ModerationCase::create(['target_type' => 'profile', 'target_id' => $this->author->id, 'title' => $this->author->name, 'subject_user_id' => $this->author->id, 'status' => 'new']);
    $this->actingAs($this->admin)->getJson(route('admin.reports.show', $case))->assertOk()
        ->assertJsonPath('context.profile.description', 'Profile being reviewed')
        ->assertJsonPath('context.profile.public_profile', false)->assertJsonMissingPath('context.profile.email')
        ->assertJsonMissingPath('context.profile.discord_link_token_hash')->assertJsonMissingPath('context.profile.password');
});

it('loads note and application context with readable answers and distinct author and member identities', function () {
    $note = GroupUserNote::create(['severity' => 'info', 'group_id' => $this->group->id, 'user_id' => $this->reporter->id, 'author_user_id' => $this->author->id, 'body' => 'Reported note']);
    $case = ModerationCase::create(['target_type' => 'member_note', 'target_id' => $note->id, 'title' => 'Note', 'subject_user_id' => $this->author->id, 'status' => 'new']);
    $this->actingAs($this->admin)->getJson(route('admin.reports.show', $case))->assertOk()
        ->assertJsonPath('subject_user.id', $this->author->id)->assertJsonPath('context.member.id', $this->reporter->id)
        ->assertJsonPath('context.preview.text', 'Reported note');
    $application = GroupMembershipApplication::factory()->create(['group_id' => $this->group->id, 'user_id' => $this->reporter->id,
        'answers' => ['experience' => 'new'], 'form_snapshot' => [['id' => 'experience', 'name' => ['en' => 'Experience'], 'type' => 'select', 'options' => [['id' => 'new', 'label' => ['en' => 'New player']]]]],
    ]);
    $case = ModerationCase::create(['target_type' => 'membership_application', 'target_id' => $application->id, 'title' => 'Application', 'subject_user_id' => $this->reporter->id, 'status' => 'new']);
    $this->getJson(route('admin.reports.show', $case))->assertOk()->assertJsonPath('context.preview.fields.0.label', 'Experience')
        ->assertJsonPath('context.preview.fields.0.value', 'New player');
});

it('requires authentication and validates reasons and other details', function () {
    $this->postJson(route('reports.store'), $this->payload)->assertUnauthorized();
    $this->actingAs($this->reporter)->postJson(route('reports.store'), array_replace($this->payload, ['reason' => 'other']))->assertUnprocessable();
    $this->postJson(route('reports.store'), array_replace($this->payload, ['target_type' => User::class]))->assertUnprocessable();
    $this->postJson(route('reports.store'), array_replace($this->payload, ['reason' => 'other', 'details' => '   ']))->assertUnprocessable();
    $this->postJson(route('reports.store'), array_replace($this->payload, ['reason' => 'other', 'details' => 'Personal data in the guide']))->assertCreated();
});

it('does not let users report inaccessible private resources or guess their existence', function () {
    $this->resource->update(['access_level' => 'admin']);
    $this->actingAs($this->reporter)->postJson(route('reports.store'), $this->payload)->assertNotFound();
    $this->postJson(route('reports.store'), array_replace($this->payload, ['target_id' => 999999]))->assertNotFound();
    expect(ContentReport::count())->toBe(0);
});

it('groups distinct reporters, deduplicates retries, and keeps separate immutable snapshots', function () {
    $case = moderation_report($this);
    moderation_report($this);
    $revision = $this->resource->publishedRevision;
    $revision->update(['snapshot' => array_replace($revision->snapshot, ['description' => 'Changed description'])]);
    moderation_report($this, User::factory()->create());
    expect(ModerationCase::count())->toBe(1)->and($case->reports()->count())->toBe(2)
        ->and($case->reports()->oldest('id')->first()->snapshot['content']['description'])->toBe('Original description');
    $this->resource->delete();
    expect($case->reports()->count())->toBe(2);
});

it('restricts reports, evidence and action endpoints to website administrators', function () {
    $case = moderation_report($this);
    $this->getJson(route('admin.reports.show', $case))->assertForbidden();
    $this->get(route('admin.reports.index'))->assertForbidden();
    moderation_action($this, $case, 'claim', $this->reporter)->assertForbidden();
    $this->getJson(route('admin.reports.evidence', $case->reports()->first()))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.reports.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Reports')->has('cases.data', 1));
});

it('requires claiming and rejects stale actions without applying changes', function () {
    $case = moderation_report($this);
    moderation_action($this, $case, 'hide')->assertConflict();
    moderation_action($this, $case, 'claim')->assertOk();
    $version = $case->fresh()->version;
    moderation_report($this, User::factory()->create());
    $this->actingAs($this->admin)->postJson(route('admin.reports.action', $case), ['version' => $version, 'action' => 'hide', 'reason' => 'Old decision'])->assertConflict();
    expect($this->resource->fresh()->moderation_hidden_at)->toBeNull();
});

it('hides resources from public and member queries without deleting or unpublishing them and restores them', function () {
    $case = moderation_report($this);
    moderation_action($this, $case, 'claim')->assertOk();
    moderation_action($this, $case, 'hide')->assertOk()->assertJsonPath('is_hidden', true);
    expect(app(ResourceReaderService::class)->query($this->group, null, true)->whereKey($this->resource->id)->exists())->toBeFalse()
        ->and($this->resource->fresh()->status)->toBe('published');
    moderation_resolve($this, $case, 'hidden')->assertOk();
    moderation_action($this, $case, 'restore')->assertOk();
    expect(app(ResourceReaderService::class)->query($this->group, null, true)->whereKey($this->resource->id)->exists())->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'moderation.hide']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'moderation.restore']);
});

it('preserves uploaded evidence privately after the original upload is deleted', function () {
    Storage::disk('local')->put('test-evidence.png', 'original image bytes');
    $image = GroupResourceImage::create([
        'uuid' => (string) Str::uuid(), 'group_id' => $this->group->id,
        'uploader_user_id' => $this->author->id, 'path' => 'test-evidence.png', 'original_name' => 'example.png',
        'mime_type' => 'image/png', 'size_bytes' => 20, 'width' => 1, 'height' => 1, 'library_upload' => true, 'access_level' => 'everyone',
    ]);
    $this->payload = ['target_type' => 'upload', 'target_id' => $image->id, 'reason' => 'spam'];
    $case = moderation_report($this, $this->group->owner);
    $report = $case->reports()->first();
    moderation_action($this, $case, 'claim')->assertOk();
    moderation_action($this, $case, 'hide')->assertOk();
    expect(app(ResourceImageService::class)->canRead($image->fresh(), $this->group->owner, false))->toBeFalse();
    expect(ReportFeedback::where('audience', 'owner')->sole()->recipients()->pluck('user_id')->all())
        ->toEqualCanonicalizing([$this->author->id, $this->group->owner_id]);
    $image->delete();
    Storage::disk('local')->delete('test-evidence.png');
    $this->actingAs($this->admin)->get(route('admin.reports.evidence', $report))->assertOk()->assertStreamedContent('original image bytes');
    $this->actingAs($this->reporter)->get(route('admin.reports.evidence', $report))->assertForbidden();
});

it('requires feedback matching the action and notifies every reporter despite optional notification preferences', function () {
    $case = moderation_report($this);
    $second = User::factory()->create();
    moderation_report($this, $second);
    moderation_action($this, $case, 'claim')->assertOk();
    moderation_resolve($this, $case)->assertConflict();
    moderation_action($this, $case, 'dismiss')->assertOk();
    moderation_resolve($this, $case, 'banned')->assertUnprocessable();
    moderation_resolve($this, $case)->assertOk();
    expect($case->fresh()->status)->toBe('resolved')->and(UserNotification::count())->toBe(2)
        ->and(ReportFeedbackRecipient::count())->toBe(2)->and(ReportFeedback::count())->toBe(1);
    moderation_resolve($this, $case)->assertConflict();
    expect(UserNotification::count())->toBe(2);
});

it('notifies the content owner and group owner on hiding and restoring without disclosing reports', function () {
    $this->author->update(['system_notice_notifications' => false]);
    $this->resource->update(['author_user_id' => $this->author->id]);
    $editor = User::factory()->create();
    $this->resource->publishedRevision->update(['editor_user_id' => $editor->id]);
    $this->payload['reason'] = 'other';
    $this->payload['details'] = 'Private reporter evidence';
    $case = moderation_report($this);
    moderation_action($this, $case, 'claim')->assertOk();
    moderation_action($this, $case, 'hide')->assertOk();

    $notice = ReportFeedback::where('audience', 'owner')->sole();
    expect($notice->template)->toBe('hidden')->and($notice->message)->toBeNull()
        ->and($notice->moderation_action_id)->toBe($case->actions()->where('action', 'hide')->sole()->id)
        ->and($notice->recipients()->pluck('user_id')->all())->toEqualCanonicalizing([$this->author->id, $this->group->owner_id])
        ->and(UserNotification::pluck('user_id')->all())->toEqualCanonicalizing([$this->author->id, $this->group->owner_id]);
    $event = NotificationEvent::where('type', 'moderation.content.hidden')->sole();
    expect($event->is_mandatory)->toBeTrue()->and($event->message_params)->toBe(['item' => 'Reported guide']);
    moderation_action($this, $case, 'hide')->assertConflict();
    expect(ReportFeedback::count())->toBe(1)->and(UserNotification::count())->toBe(2);

    $recipient = $notice->recipients()->where('user_id', $this->author->id)->sole();
    $this->actingAs($this->author)->getJson(route('reports.feedback.pending'))->assertOk()
        ->assertJsonPath('next.audience', 'owner')->assertJsonPath('next.template', 'hidden')
        ->assertJsonMissingPath('next.case')->assertJsonMissingPath('next.reporter')->assertJsonMissingPath('next.reason')
        ->assertDontSee('Private reporter evidence')->assertDontSee('Reviewed original evidence');
    $this->get(route('account.notifications.open', UserNotification::where('user_id', $this->author->id)->sole()))->assertRedirect();
    expect($recipient->fresh()->acknowledged_at)->toBeNull();
    $this->actingAs($this->reporter)->postJson(route('reports.feedback.acknowledge', $recipient))->assertNotFound();
    $this->actingAs($this->author)->postJson(route('reports.feedback.acknowledge', $recipient))->assertOk()->assertJsonPath('count', 0);

    moderation_resolve($this, $case, 'hidden')->assertOk();
    moderation_action($this, $case, 'restore')->assertOk();
    $this->actingAs($this->author)->getJson(route('reports.feedback.pending'))->assertOk()
        ->assertJsonPath('next.template', 'restored')->assertJsonPath('next.audience', 'owner')->assertJsonPath('count', 1);
    expect($recipient->fresh()->acknowledged_at)->not->toBeNull()
        ->and($notice->fresh()->template)->toBe('hidden')
        ->and(ReportFeedback::where('audience', 'owner')->count())->toBe(2);
});

it('deduplicates an owner who also owns the group and shares one pending queue with reporter feedback', function () {
    $this->resource->update(['author_user_id' => $this->group->owner_id]);
    $case = moderation_report($this, $this->group->owner);
    moderation_action($this, $case, 'claim')->assertOk();
    moderation_action($this, $case, 'hide')->assertOk();
    expect(ReportFeedbackRecipient::count())->toBe(1);
    moderation_resolve($this, $case, 'hidden')->assertOk();
    $this->actingAs($this->group->owner)->getJson(route('reports.feedback.pending'))
        ->assertJsonPath('next.audience', 'owner')->assertJsonPath('count', 2);
    $first = ReportFeedbackRecipient::oldest('id')->first();
    $this->postJson(route('reports.feedback.acknowledge', $first))->assertOk()
        ->assertJsonPath('next.audience', 'reporter')->assertJsonPath('count', 1);
});

it('keeps owner popups accessible on the banned-account page', function () {
    $case = moderation_report($this);
    moderation_action($this, $case, 'claim')->assertOk();
    moderation_action($this, $case, 'hide')->assertOk();
    moderation_action($this, $case, 'ban')->assertOk();
    $this->actingAs($this->author->fresh())->get(route('account.banned'))->assertOk();
    $response = $this->getJson(route('reports.feedback.pending'))->assertOk()->assertJsonPath('next.audience', 'owner');
    $this->postJson(route('reports.feedback.acknowledge', $response->json('next.id')))->assertOk();
});

it('rolls back the moderation decision if its owner notice cannot be delivered to the inbox', function () {
    $case = moderation_report($this);
    moderation_action($this, $case, 'claim')->assertOk();
    $version = $case->fresh()->version;
    $this->partialMock(NotificationService::class, function ($mock) {
        $mock->shouldReceive('sendInAppNotifications')->once()->andThrow(new RuntimeException('Inbox unavailable'));
    });
    $this->withoutExceptionHandling();
    expect(fn () => moderation_action($this, $case, 'hide'))->toThrow(RuntimeException::class, 'Inbox unavailable');
    expect($this->resource->fresh()->moderation_hidden_at)->toBeNull()
        ->and($case->fresh()->version)->toBe($version)
        ->and($case->actions()->where('action', 'hide')->exists())->toBeFalse()
        ->and(ReportFeedback::count())->toBe(0)->and(ReportFeedbackRecipient::count())->toBe(0)
        ->and(NotificationEvent::where('type', 'moderation.content.hidden')->exists())->toBeFalse();
});

it('shows current corrected content to admins while retaining the original report evidence', function () {
    $case = moderation_report($this);
    moderation_action($this, $case, 'claim')->assertOk();
    moderation_action($this, $case, 'hide')->assertOk();
    $revision = $this->resource->publishedRevision;
    $revision->update(['snapshot' => array_replace($revision->snapshot, ['title' => 'Corrected title', 'description' => 'Fixed content'])]);
    $this->actingAs($this->admin)->getJson(route('admin.reports.show', $case))->assertOk()
        ->assertJsonPath('current_content.title', 'Corrected title')
        ->assertJsonPath('case.reports.0.snapshot.title', 'Reported guide');
});

it('reopens completed reviews and preserves evidence, earlier feedback and every reversal', function () {
    $case = moderation_report($this);
    moderation_action($this, $case, 'claim')->assertOk();
    moderation_action($this, $case, 'dismiss')->assertOk();
    moderation_resolve($this, $case)->assertOk();
    $original = $case->reports()->sole()->snapshot;
    $feedback = $case->feedback()->sole();
    $staleVersion = $case->fresh()->version;
    moderation_action($this, $case, 'reopen')->assertOk()->assertJsonPath('case.status', 'in_review');
    expect($case->fresh()->open_key)->toBe('resource:'.$this->resource->id)->and($case->fresh()->resolved_at)->toBeNull();
    $this->postJson(route('admin.reports.action', $case), ['version' => $staleVersion, 'action' => 'hide', 'reason' => 'Stale screen'])->assertConflict();
    moderation_action($this, $case, 'hide')->assertOk();
    moderation_resolve($this, $case, 'hidden')->assertOk();
    moderation_action($this, $case, 'restore')->assertOk();
    moderation_action($this, $case, 'reopen')->assertOk();
    moderation_action($this, $case, 'ban')->assertOk();
    moderation_resolve($this, $case, 'banned')->assertOk();

    $otherAdmin = User::factory()->create(['is_admin' => true]);
    moderation_action($this, $case, 'unban', $otherAdmin)->assertConflict();
    moderation_action($this, $case, 'claim', $otherAdmin)->assertOk();
    moderation_action($this, $case, 'unban', $otherAdmin)->assertOk();
    expect($this->author->fresh()->banned_at)->toBeNull()
        ->and($case->reports()->sole()->snapshot)->toBe($original)
        ->and($feedback->fresh()->template)->toBe('no_violation')
        ->and($case->actions()->where('action', 'reopen')->count())->toBe(2);
    $this->assertDatabaseHas('audit_logs', ['action' => 'moderation.reopen']);
});

it('does not reopen a second review when a newer case for the same target is open', function () {
    $case = moderation_report($this);
    moderation_action($this, $case, 'claim')->assertOk();
    moderation_action($this, $case, 'dismiss')->assertOk();
    moderation_resolve($this, $case)->assertOk();
    $new = moderation_report($this);
    moderation_action($this, $case, 'reopen')->assertConflict();
    expect($case->fresh()->status)->toBe('resolved')->and($case->fresh()->open_key)->toBeNull()
        ->and($new->fresh()->open_key)->toBe('resource:'.$this->resource->id);
});

it('keeps modal acknowledgement independent of fetching and reading notifications and scoped to each reporter', function () {
    $case = moderation_report($this);
    $second = User::factory()->create();
    moderation_report($this, $second);
    moderation_action($this, $case, 'claim')->assertOk();
    moderation_action($this, $case, 'dismiss')->assertOk();
    moderation_resolve($this, $case)->assertOk();
    $recipient = ReportFeedbackRecipient::where('user_id', $this->reporter->id)->first();
    $other = ReportFeedbackRecipient::where('user_id', $second->id)->first();
    $this->actingAs($this->reporter)->getJson(route('reports.feedback.pending'))->assertOk()->assertJsonPath('count', 1);
    $notification = UserNotification::where('user_id', $this->reporter->id)->first();
    $this->get(route('account.notifications.open', $notification))->assertRedirect();
    expect($recipient->fresh()->acknowledged_at)->toBeNull();
    $this->postJson(route('reports.feedback.acknowledge', $other))->assertNotFound();
    $this->postJson(route('reports.feedback.acknowledge', $recipient))->assertOk()->assertJsonPath('count', 0);
    $this->postJson(route('reports.feedback.acknowledge', $recipient))->assertOk();
    expect($other->fresh()->acknowledged_at)->toBeNull();
});

it('allows a new case after resolution and appends later feedback without losing the first acknowledgement', function () {
    $case = moderation_report($this);
    moderation_action($this, $case, 'claim')->assertOk();
    moderation_action($this, $case, 'dismiss')->assertOk();
    moderation_resolve($this, $case)->assertOk();
    $new = moderation_report($this);
    expect($new->id)->not->toBe($case->id);
    moderation_action($this, $new, 'claim')->assertOk();
    moderation_action($this, $new, 'dismiss')->assertOk();
    moderation_resolve($this, $new)->assertOk();
    $this->actingAs($this->reporter)->getJson(route('reports.feedback.pending'))->assertJsonPath('count', 2);
    $first = ReportFeedbackRecipient::where('user_id', $this->reporter->id)->oldest('id')->first();
    $this->postJson(route('reports.feedback.acknowledge', $first))->assertOk()->assertJsonPath('count', 1)
        ->assertJsonPath('next.id', ReportFeedbackRecipient::latest('id')->first()->id);
});

it('bans and unbans the known content author and blocks existing authenticated sessions', function () {
    $case = moderation_report($this);
    moderation_action($this, $case, 'claim')->assertOk();
    moderation_action($this, $case, 'ban')->assertOk();
    expect($this->author->fresh()->banned_at)->not->toBeNull();
    $this->actingAs($this->author->fresh())->getJson(route('reports.feedback.pending'))->assertOk();
    $this->getJson(route('account.notifications.summary'))->assertForbidden();
    $this->postJson(route('reports.store'), $this->payload)->assertForbidden();
    moderation_action($this, $case, 'unban')->assertOk();
    $this->actingAs($this->author->fresh())->getJson(route('account.notifications.summary'))->assertOk();
});

it('protects administrator accounts from bans', function () {
    $this->resource->publishedRevision->update(['editor_user_id' => $this->admin->id]);
    $case = moderation_report($this);
    moderation_action($this, $case, 'claim')->assertOk();
    moderation_action($this, $case, 'ban')->assertUnprocessable();
    expect($this->admin->fresh()->banned_at)->toBeNull();
});

it('does not allow hidden holsters to be reactivated by normal edits', function () {
    $holster = BozjaHolster::create(['group_id' => $this->group->id, 'name' => ['en' => 'Test holster'], 'is_active' => true]);
    $this->payload = ['target_type' => 'holster', 'target_id' => $holster->id, 'reason' => 'spam'];
    $case = moderation_report($this, $this->group->owner);
    moderation_action($this, $case, 'claim')->assertOk();
    moderation_action($this, $case, 'hide')->assertOk();
    $holster->refresh()->update(['is_active' => true]);
    expect($holster->fresh()->is_active)->toBeFalse()->and(BozjaHolster::schemaOptionsForGroup($this->group->id))->toBe([]);
    moderation_action($this, $case, 'restore')->assertOk();
    expect($holster->fresh()->is_active)->toBeTrue();
    expect($case->subject_user_id)->toBeNull()
        ->and(ReportFeedback::where('audience', 'owner')->count())->toBe(2)
        ->and(ReportFeedbackRecipient::distinct()->pluck('user_id')->all())->toBe([$this->group->owner_id]);
});

it('rate limits report submission per account', function () {
    $this->actingAs($this->reporter);
    for ($i = 0; $i < 5; $i++) {
        $this->postJson(route('reports.store'), $this->payload)->assertCreated();
    }
    $this->postJson(route('reports.store'), $this->payload)->assertStatus(429);
});

it('blocks banned Passport users at the API middleware', function () {
    mock_test_passport_resource_server();
    $this->author->forceFill(['banned_at' => now()])->save();
    Passport::actingAs($this->author->fresh(), ['xivplugin:read']);
    $this->getJson(route('api.xivplugin.me'))->assertForbidden();
});

it('keeps private notes restricted to their group moderators and attributes them to the actual author', function () {
    $note = GroupUserNote::create(['group_id' => $this->group->id, 'user_id' => $this->reporter->id,
        'author_user_id' => $this->author->id, 'body' => 'Internal note', 'severity' => 'info']);
    $this->payload = ['target_type' => 'member_note', 'target_id' => $note->id, 'reason' => 'harassment'];
    $this->actingAs($this->reporter)->postJson(route('reports.store'), $this->payload)->assertNotFound();
    $case = moderation_report($this, $this->group->owner);
    expect($case->subject_user_id)->toBe($this->author->id);
    moderation_action($this, $case, 'claim')->assertOk();
    moderation_action($this, $case, 'hide')->assertUnprocessable();
});

it('reopens review when new evidence arrives before feedback is sent', function () {
    $case = moderation_report($this);
    moderation_action($this, $case, 'claim')->assertOk();
    moderation_action($this, $case, 'dismiss')->assertOk();
    $version = $case->fresh()->version;
    moderation_report($this, User::factory()->create());
    expect($case->fresh()->status)->toBe('in_review');
    $this->actingAs($this->admin)->postJson(route('admin.reports.feedback', $case), ['version' => $version, 'template' => 'no_violation'])->assertConflict();
    expect(ReportFeedback::count())->toBe(0);
});

it('does not serve a moderated upload through the shared image response used by integrations', function () {
    $image = new GroupResourceImage(['moderation_hidden_at' => now(), 'path' => 'hidden.png']);
    expect(fn () => app(ResourceImageService::class)->response($image))->toThrow(HttpException::class);
});
