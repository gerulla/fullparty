<?php

use App\Models\AuditLog;
use App\Models\ChangelogEntry;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Changelog\ChangelogReader;
use App\Services\Changelog\ChangelogService;
use App\Services\Changelog\ChangelogVersions;
use App\Services\DeploymentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function changelog_test_payload(string $mode = 'current'): array
{
    return [
        'revision' => 1,
        'version_mode' => $mode,
        'version_context' => app(ChangelogVersions::class)->choices(),
        'translations' => ['en' => [
            'title' => 'A better FullParty',
            'body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'New features and fixes.']]]]],
        ]],
    ];
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->releaseVersion = 'v1.6.4';
    $this->releaseCommit = 'release-commit';
    $this->partialMock(DeploymentVersion::class, function ($mock) {
        $mock->shouldReceive('metadata')->andReturnUsing(fn () => ['version' => $this->releaseVersion, 'commit' => $this->releaseCommit, 'deployed_at' => null]);
    });
    $this->admin = User::factory()->admin()->create();
});

it('allows admins to create a draft using deployed version metadata and records an audit event', function () {
    $this->actingAs($this->admin)->get(route('admin.changelog.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Changelog/Edit')->where('versionChoices.current_version', '1.6.4')->where('versionChoices.can_use_range', false));
    $this->post(route('admin.changelog.store'), changelog_test_payload())->assertRedirect();
    $entry = ChangelogEntry::firstOrFail();
    expect($entry->version_to)->toBe('1.6.4')->and($entry->commit)->toBe('release-commit')->and($entry->is_published)->toBeFalse();
    expect(AuditLog::where('action', 'admin.changelog.created')->where('actor_user_id', $this->admin->id)->exists())->toBeTrue();
    $this->getJson(route('changelog.latest'))->assertExactJson(['entry' => null]);
    $this->getJson(route('changelog.show', $entry))->assertNotFound();
    $this->getJson(route('changelog.index'))->assertJsonCount(0, 'data');
});

it('requires admin permission for every administration endpoint', function () {
    $entry = app(ChangelogService::class)->save($this->admin, changelog_test_payload());
    $this->actingAs(User::factory()->create());
    foreach (['index', 'create', 'edit'] as $action) {
        $this->getJson(route('admin.changelog.'.$action, $action === 'edit' ? $entry : []))->assertForbidden();
    }
    $this->postJson(route('admin.changelog.store'), changelog_test_payload())->assertForbidden();
    $this->putJson(route('admin.changelog.update', $entry), changelog_test_payload())->assertForbidden();
    foreach (['publish', 'unpublish'] as $action) {
        $this->postJson(route('admin.changelog.'.$action, $entry), ['revision' => 1])->assertForbidden();
    }
    $this->assertDatabaseCount('changelog_entries', 1);
});

it('requires a version choice and rejects fabricated versions or a first-entry range', function () {
    $this->actingAs($this->admin);
    $data = changelog_test_payload();
    $data['version_mode'] = '';
    $this->postJson(route('admin.changelog.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('version_mode');
    $data = changelog_test_payload();
    $data['version_context']['current_version'] = '99.0.0';
    $this->postJson(route('admin.changelog.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('version_mode');
    $this->postJson(route('admin.changelog.store'), changelog_test_payload('since_last'))->assertUnprocessable()->assertJsonValidationErrors('version_mode');
    $this->assertDatabaseCount('changelog_entries', 0);
});

it('rejects missing or invalid deployed release versions', function (string $version) {
    $this->releaseVersion = $version;
    expect(app(ChangelogVersions::class)->choices()['current_version'])->toBeNull();
    $this->actingAs($this->admin)->postJson(route('admin.changelog.store'), changelog_test_payload())->assertUnprocessable()->assertJsonValidationErrors('version_mode');
})->with(['dev', '', 'v1.2', '1.02.3', '1.6.4-branch']);

it('publishes saved content for guests, tracks reads per account, and preserves read state when viewing older entries', function () {
    $service = app(ChangelogService::class);
    $older = $service->save($this->admin, changelog_test_payload());
    $service->visibility($this->admin, $older, 1, true);
    $this->releaseVersion = '1.6.5';
    $entry = $service->save($this->admin, changelog_test_payload('since_last'));
    $this->actingAs($this->admin)->post(route('admin.changelog.publish', $entry), ['revision' => 1])->assertRedirect();
    expect($entry->fresh()->version_from)->toBe('1.6.4');
    auth()->logout();
    $this->getJson(route('changelog.latest'))->assertOk()->assertJsonPath('entry.id', $entry->id)->assertJsonPath('entry.version_to', '1.6.5')->assertJsonMissingPath('entry.created_by');
    $this->postJson(route('changelog.read', $entry))->assertUnauthorized();
    $user = User::factory()->create();
    $reader = app(ChangelogReader::class);
    expect($reader->summary($user)['unread'])->toBeTrue();
    $this->actingAs($user)->postJson(route('changelog.read', $entry))->assertNoContent();
    $this->postJson(route('changelog.read', $older))->assertNoContent();
    expect($reader->summary($user)['unread'])->toBeFalse()->and($reader->summary($this->admin)['unread'])->toBeTrue();
});

it('requires explicit review and saving when deployment or latest published entry changes', function () {
    $service = app(ChangelogService::class);
    $entry = $service->save($this->admin, changelog_test_payload());
    $this->releaseVersion = '1.6.5';
    $this->actingAs($this->admin)->postJson(route('admin.changelog.publish', $entry), ['revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('version_mode');
    $this->putJson(route('admin.changelog.update', $entry), changelog_test_payload())->assertRedirect();
    $other = $service->save($this->admin, changelog_test_payload());
    $service->visibility($this->admin, $other, 1, true);
    $this->postJson(route('admin.changelog.publish', $entry), ['revision' => 2])->assertUnprocessable()->assertJsonValidationErrors('version_mode');
    expect($entry->fresh()->is_published)->toBeFalse();
});

it('detects a changed deployment commit even when the version number did not change', function () {
    $entry = app(ChangelogService::class)->save($this->admin, changelog_test_payload());
    $this->releaseCommit = 'new-commit';
    $this->actingAs($this->admin)->postJson(route('admin.changelog.publish', $entry), ['revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('version_mode');
});

it('does not allow a range for the same version or publication from a rolled back deployment', function () {
    $service = app(ChangelogService::class);
    $entry = $service->save($this->admin, changelog_test_payload());
    $service->visibility($this->admin, $entry, 1, true);
    expect(app(ChangelogVersions::class)->choices()['can_use_range'])->toBeFalse();
    $this->actingAs($this->admin)->postJson(route('admin.changelog.store'), changelog_test_payload('since_last'))->assertUnprocessable();
    $this->releaseVersion = '1.6.3';
    $this->postJson(route('admin.changelog.store'), changelog_test_payload())->assertUnprocessable();
});

it('keeps published version boundaries immutable through edits, unpublishing and republishing', function () {
    $service = app(ChangelogService::class);
    $first = $service->save($this->admin, changelog_test_payload());
    $service->visibility($this->admin, $first, 1, true);
    $this->releaseVersion = '1.6.9';
    $entry = $service->save($this->admin, changelog_test_payload('since_last'));
    $service->visibility($this->admin, $entry, 1, true);
    $publishedAt = $entry->fresh()->published_at->toIso8601String();
    $this->releaseVersion = '1.7.0';
    $data = changelog_test_payload();
    $data['revision'] = 2;
    $data['translations']['en']['title'] = 'Corrected wording';
    $this->actingAs($this->admin)->putJson(route('admin.changelog.update', $entry), $data)->assertRedirect();
    $this->postJson(route('admin.changelog.unpublish', $entry), ['revision' => 3])->assertRedirect();
    $this->postJson(route('admin.changelog.publish', $entry), ['revision' => 4])->assertRedirect();
    $entry->refresh();
    expect($entry->version_from)->toBe('1.6.4')->and($entry->version_to)->toBe('1.6.9')->and($entry->published_at->toIso8601String())->toBe($publishedAt);
});

it('rejects stale edits and stale publication requests without overwriting another administrator', function () {
    $entry = app(ChangelogService::class)->save($this->admin, changelog_test_payload());
    $this->actingAs($this->admin)->putJson(route('admin.changelog.update', $entry), changelog_test_payload())->assertRedirect();
    $this->putJson(route('admin.changelog.update', $entry), changelog_test_payload())->assertUnprocessable()->assertJsonValidationErrors('revision');
    $this->postJson(route('admin.changelog.publish', $entry), ['revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('revision');
    expect($entry->fresh()->revision)->toBe(2)->and($entry->fresh()->is_published)->toBeFalse();
});

it('invalidates cached content and latest metadata on edits and unpublishing', function () {
    $service = app(ChangelogService::class);
    $entry = $service->save($this->admin, changelog_test_payload());
    $service->visibility($this->admin, $entry, 1, true);
    $this->getJson(route('changelog.latest'))->assertJsonPath('entry.title', 'A better FullParty');
    $data = changelog_test_payload();
    $data['revision'] = 2;
    $data['translations']['en']['title'] = 'Corrected';
    $this->actingAs($this->admin)->putJson(route('admin.changelog.update', $entry), $data)->assertRedirect();
    $this->getJson(route('changelog.latest'))->assertJsonPath('entry.title', 'Corrected');
    $this->postJson(route('admin.changelog.unpublish', $entry), ['revision' => 3])->assertRedirect();
    $this->getJson(route('changelog.latest'))->assertExactJson(['entry' => null]);
    $this->getJson(route('changelog.show', $entry))->assertNotFound();
    $this->postJson(route('changelog.read', $entry))->assertNotFound();
    expect(app(ChangelogReader::class)->summary($this->admin))->toBe(['latest_id' => null, 'unread' => false]);
});

it('validates rich text and translations and preserves content whitespace', function () {
    $this->actingAs($this->admin);
    $data = changelog_test_payload();
    $data['translations']['en']['body']['content'][0]['content'][0]['marks'] = [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]];
    $this->postJson(route('admin.changelog.store'), $data)->assertUnprocessable();
    $data = changelog_test_payload();
    $data['translations']['fr'] = ['title' => 'Titre', 'body' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]];
    $this->postJson(route('admin.changelog.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('translations.fr.body');
    $data = changelog_test_payload();
    $data['translations']['en']['body']['content'][0]['content'][0]['text'] = ' before and after ';
    $this->postJson(route('admin.changelog.store'), $data)->assertRedirect();
    expect(ChangelogEntry::first()->translations['en']['body']['content'][0]['content'][0]['text'])->toBe(' before and after ');
});

it('returns optional translations with English fallback and omits bodies from the archive', function () {
    $data = changelog_test_payload();
    $data['translations']['de'] = [...$data['translations']['en'], 'title' => 'Neuigkeiten'];
    $entry = app(ChangelogService::class)->save($this->admin, $data);
    app(ChangelogService::class)->visibility($this->admin, $entry, 1, true);
    $this->getJson(route('changelog.latest', ['locale' => 'de']))->assertJsonPath('entry.title', 'Neuigkeiten');
    $this->getJson(route('changelog.latest', ['locale' => 'ja']))->assertJsonPath('entry.title', 'A better FullParty');
    $this->getJson(route('changelog.index'))->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.body')->assertJsonMissingPath('data.0.translations');
});

it('rolls back publication if its audit event fails', function () {
    $entry = app(ChangelogService::class)->save($this->admin, changelog_test_payload());
    $revision = DB::table('changelog_publication_state')->value('revision');
    $this->mock(AuditLogger::class)->shouldReceive('log')->andThrow(new RuntimeException('Audit unavailable'));
    expect(fn () => app(ChangelogService::class)->visibility($this->admin, $entry, 1, true))->toThrow(RuntimeException::class);
    expect($entry->fresh()->is_published)->toBeFalse()->and(DB::table('changelog_publication_state')->value('revision'))->toBe($revision);
});
