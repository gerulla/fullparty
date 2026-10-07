<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\DiscordUserIntegration;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceImage;
use App\Models\IntegrationClient;
use App\Models\User;
use App\Services\Groups\ActivityApplicationCharacterRefreshService;
use App\Support\Integrations\IntegrationPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->member = User::factory()->create();
    $this->link = DiscordUserIntegration::create(['user_id' => $this->member->id, 'discord_user_id' => '123456789012345678', 'user_app_installed_at' => now()]);
    $this->token = IntegrationClient::makePlainApiToken();
    $this->client = IntegrationClient::factory()->withApiToken($this->token)->create(['scopes' => [IntegrationPermissions::MEMBERS_READ, IntegrationPermissions::MEMBERS_WRITE]]);
    $this->headers = ['Authorization' => 'Bearer '.$this->token, 'X-FullParty-Discord-User-Id' => $this->link->discord_user_id];
});

it('requires both an active client and an explicit linked actor even without an accept header', function () {
    $this->get('/api/integrations/v1/me')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    $this->get('/api/integrations/v1/me', ['Authorization' => 'Bearer '.$this->token])->assertUnprocessable();
    $this->get('/api/integrations/v1/me', [...$this->headers, 'X-FullParty-Discord-User-Id' => '999'])->assertNotFound();
    $this->link->update(['revoked_at' => now()]);
    $this->get('/api/integrations/v1/me', $this->headers)->assertNotFound();
});

it('separates member read and write permissions from bot scopes', function () {
    $run = Activity::factory()->create(['status' => Activity::STATUS_SCHEDULED]);
    $this->getJson('/api/integrations/v1/bot/runs/'.$run->id, $this->headers)->assertForbidden();
    $this->client->update(['scopes' => [IntegrationClient::SCOPE_USERS_READ, IntegrationClient::SCOPE_USERS_WRITE]]);
    $this->getJson('/api/integrations/v1/me', $this->headers)->assertForbidden();
    $this->client->update(['scopes' => [IntegrationPermissions::MEMBERS_READ]]);
    $this->patchJson('/api/integrations/v1/me/name', ['username' => 'Denied'], $this->headers)->assertForbidden();
    expect($this->member->fresh()->name)->not->toBe('Denied');
});

it('rejects banned and unverified actors', function () {
    $this->member->forceFill(['banned_at' => now()])->save();
    $this->getJson('/api/integrations/v1/me', $this->headers)->assertForbidden();
    $this->member->forceFill(['banned_at' => null, 'email_verified_at' => null])->save();
    $this->getJson('/api/integrations/v1/me', $this->headers)->assertForbidden();
});

it('returns only the selected account and restores auth after the request', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    $this->getJson('/api/integrations/v1/me?user_id='.$other->id, $this->headers)
        ->assertOk()->assertJsonPath('data.id', $this->member->id)
        ->assertJsonMissingPath('data.email')->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.is_admin');
    expect(auth()->getDefaultDriver())->toBe('web');
});

it('updates the linked account through the shared workflow and attributes the integration in audit', function () {
    $this->patchJson('/api/integrations/v1/me/name', ['username' => 'API member', 'is_admin' => true], $this->headers)
        ->assertOk()->assertJsonPath('name', 'API member');
    expect($this->member->fresh()->name)->toBe('API member');
    expect($this->member->fresh()->is_admin)->toBeFalse();
    $audit = AuditLog::where('action', 'user.settings.username_updated')->firstOrFail();
    expect($audit->actor_user_id)->toBe($this->member->id)->and($audit->metadata['integration_client_id'])->toBe($this->client->id);
});

it('lists and edits only owned characters and keeps verification proof private', function () {
    $mine = Character::factory()->unverified()->create(['user_id' => $this->member->id]);
    $other = Character::factory()->create();
    $this->getJson('/api/integrations/v1/characters', $this->headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id)->assertJsonMissingPath('data.0.token');
    $this->getJson('/api/integrations/v1/characters/'.$other->id, $this->headers)->assertNotFound();
    $this->putJson('/api/integrations/v1/characters/'.$other->id.'/primary', [], $this->headers)->assertForbidden();
    $this->putJson('/api/integrations/v1/characters/'.$mine->id.'/primary', [], $this->headers)->assertUnprocessable();
    $mine->update(['verified_at' => now()]);
    $this->putJson('/api/integrations/v1/characters/'.$mine->id.'/primary', [], $this->headers)->assertOk();
    expect((bool) $mine->fresh()->is_primary)->toBeTrue();
});

it('uses the same join and leave rules without requiring a browser session', function () {
    $group = Group::factory()->open()->create();
    $this->postJson('/api/integrations/v1/groups/'.$group->slug.'/join', [], $this->headers)->assertOk();
    expect($group->fresh()->hasMember($this->member->id))->toBeTrue();
    $this->getJson('/api/integrations/v1/me/groups', $this->headers)->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/integrations/v1/groups/'.$group->slug.'/leave', [], $this->headers)->assertOk();
    expect($group->fresh()->hasMember($this->member->id))->toBeFalse();
    $closed = Group::factory()->create();
    $this->postJson('/api/integrations/v1/groups/'.$closed->slug.'/join', [], $this->headers)->assertUnprocessable();
});

it('hides private groups and draft runs including from linked group owners', function () {
    $group = Group::factory()->create(['is_visible' => false]);
    $run = Activity::factory()->create(['group_id' => $group->id, 'status' => Activity::STATUS_SCHEDULED]);
    $this->getJson('/api/integrations/v1/groups/'.$group->slug, $this->headers)->assertNotFound();
    $this->getJson('/api/integrations/v1/runs/'.$run->id, $this->headers)->assertNotFound();
    $ownGroup = Group::factory()->create(['owner_id' => $this->member->id]);
    $draft = Activity::factory()->create(['group_id' => $ownGroup->id, 'status' => Activity::STATUS_DRAFT]);
    $this->getJson('/api/integrations/v1/runs/'.$draft->id, $this->headers)->assertNotFound();
    $this->getJson('/api/integrations/v1/groups/'.$ownGroup->slug.'/runs/'.$draft->id.'/application', $this->headers)->assertNotFound();
});

it('returns published runs and protects application ownership', function () {
    $run = Activity::factory()->create(['status' => Activity::STATUS_SCHEDULED]);
    $application = ActivityApplication::factory()->create(['activity_id' => $run->id, 'user_id' => $this->member->id]);
    $other = ActivityApplication::factory()->create();
    $this->getJson('/api/integrations/v1/runs/'.$run->id, $this->headers)->assertOk()->assertJsonPath('data.id', $run->id);
    $this->getJson('/api/integrations/v1/me/applications', $this->headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.guest_access_token');
    $this->getJson('/api/integrations/v1/me/applications/'.$other->id, $this->headers)->assertNotFound();
    $this->deleteJson('/api/integrations/v1/me/applications/'.$other->id, [], $this->headers)->assertNotFound();
    $this->getJson('/api/integrations/v1/me/runs', $this->headers)->assertOk()->assertJsonPath('data.0.id', $run->id);
});

it('uses the website origin for browser links on the API subdomain', function () {
    $run = Activity::factory()->create(['status' => Activity::STATUS_SCHEDULED]);
    $response = $this->getJson('http://'.config('integration_api.docs_host').'/api/integrations/v1/runs/'.$run->id, $this->headers)->assertOk();
    expect($response->json('data.url'))->toStartWith(rtrim(config('app.url'), '/').'/');
    $response->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson('/api/integrations/v1/me', [])->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
});

it('serves only published accessible resource content and protects its images', function () {
    Storage::fake('local');
    $group = Group::factory()->create();
    $group->features()->update(['resource_hub_enabled' => true]);
    $group->memberships()->create(['user_id' => $this->member->id, 'role' => 'member', 'joined_at' => now()]);
    $resource = GroupResource::factory()->create(['group_id' => $group->id, 'status' => 'published', 'access_level' => 'everyone']);
    $uuid = (string) Str::uuid();
    $image = GroupResourceImage::create(['uuid' => $uuid, 'group_id' => $group->id, 'resource_id' => $resource->id,
        'uploader_user_id' => $group->owner_id, 'path' => 'test-image.png', 'mime_type' => 'image/png', 'size_bytes' => 3, 'original_name' => 'test.png',
        'width' => 1, 'height' => 1, 'access_level' => 'everyone']);
    Storage::disk('local')->put('test-image.png', 'png');
    $revision = $resource->revisions()->create(['state' => 'published', 'editor_user_id' => $group->owner_id, 'editor' => ['name' => 'Editor'], 'summary' => 'Published', 'snapshot' => [
        'title' => 'Published guide', 'description' => '', 'access_level' => 'everyone', 'tags' => [], 'activity_type_ids' => [],
        'body' => ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => '/resource-assets/'.$uuid]]]], 'image_ids' => [$uuid],
    ]]);
    $resource->update(['published_revision_id' => $revision->id, 'working_copy' => ['title' => 'Secret draft']]);
    $path = '/api/integrations/v1/groups/'.$group->slug.'/resources/'.$resource->uuid;
    $response = $this->getJson($path, $this->headers)->assertOk()->assertJsonPath('data.title', 'Published guide')->assertJsonMissingPath('data.working_copy');
    $url = $response->json('data.images.0.url');
    expect($url)->toContain('/api/integrations/v1/resource-assets/');
    expect($response->json('data.body.content.0.attrs.src'))->toBe($url);
    $this->get($url, $this->headers)->assertOk();
    $image->update(['moderation_hidden_at' => now()]);
    $this->get($url, $this->headers)->assertNotFound();
    $resource->update(['moderation_hidden_at' => now()]);
    $this->getJson($path, $this->headers)->assertNotFound();
});

it('enforces the shared per-client rate limit across member identities', function () {
    for ($i = 0; $i < 180; $i++) {
        $this->getJson('/api/integrations/v1/me', $this->headers)->assertOk();
    }
    $other = User::factory()->create();
    $this->link->update(['user_id' => $other->id]);
    $this->getJson('/api/integrations/v1/me', $this->headers)->assertStatus(429)->assertHeader('Retry-After');
});

it('rejects excessive pagination and has no account security or roster mutation routes', function () {
    $this->getJson('/api/integrations/v1/characters?per_page=10000', $this->headers)->assertUnprocessable();
    foreach (['me/password', 'me/account', 'me/login-providers', 'runs/1/slots/1/assign'] as $path) {
        $this->postJson('/api/integrations/v1/'.$path, [], $this->headers)->assertNotFound();
    }
});

it('submits edits and withdraws run applications through the existing workflow', function () {
    $group = Group::factory()->open()->create();
    $run = Activity::factory()->create(['group_id' => $group->id, 'status' => Activity::STATUS_SCHEDULED, 'is_public' => true]);
    $run->activityTypeVersion->update(['application_schema' => [['key' => 'ready', 'label' => ['en' => 'Ready?'], 'type' => 'boolean', 'required' => true]]]);
    $character = Character::factory()->create(['user_id' => $this->member->id, 'lodestone_refreshed_at' => now()]);
    $this->mock(ActivityApplicationCharacterRefreshService::class, function ($mock) {
        $mock->shouldReceive('refreshSelectedCharacterIfDue')->andReturn(['refreshed' => false, 'available_at' => null, 'character' => null, 'fflogs_error' => null]);
    });
    $path = '/api/integrations/v1/groups/'.$group->slug.'/runs/'.$run->id.'/application';
    $this->getJson($path, $this->headers)->assertOk()->assertJsonPath('applicationSchema.0.key', 'ready');
    $this->postJson($path, ['selected_character_id' => $character->id], $this->headers)->assertUnprocessable();
    $response = $this->postJson($path, ['selected_character_id' => $character->id, 'answers' => ['ready' => true]], $this->headers)->assertOk();
    $id = $response->json('data.id');
    $this->postJson($path, ['selected_character_id' => $character->id], $this->headers)->assertOk()->assertJsonPath('data.id', $id);
    $this->putJson($path, ['selected_character_id' => $character->id, 'answers' => ['ready' => false], 'notes' => 'Changed'], $this->headers)->assertOk()->assertJsonPath('data.notes', 'Changed');
    $this->deleteJson('/api/integrations/v1/me/applications/'.$id, [], $this->headers)->assertOk();
    expect(ActivityApplication::findOrFail($id)->status)->toBe(ActivityApplication::STATUS_WITHDRAWN);
});

it('does not accept unverified characters or another members character in an application', function () {
    $run = Activity::factory()->create(['status' => Activity::STATUS_SCHEDULED, 'is_public' => true]);
    $run->activityTypeVersion->update(['application_schema' => []]);
    $path = '/api/integrations/v1/groups/'.$run->group->slug.'/runs/'.$run->id.'/application';
    $unverified = Character::factory()->unverified()->create(['user_id' => $this->member->id]);
    $other = Character::factory()->create();
    foreach ([$unverified, $other] as $character) {
        $this->postJson($path, ['selected_character_id' => $character->id], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('selected_character_id');
    }
    expect(ActivityApplication::where('activity_id', $run->id)->count())->toBe(0);
});

it('submits and edits membership requests using the returned schema', function () {
    $group = Group::factory()->create(['join_mode' => Group::JOIN_MODE_APPLICATION]);
    $path = '/api/integrations/v1/groups/'.$group->slug.'/membership-application';
    $this->getJson($path, $this->headers)->assertOk()->assertJsonPath('formSchema.0.id', 'are_you_a_gamer');
    $this->postJson($path, ['answers' => ['are_you_a_gamer' => true]], $this->headers)->assertOk()->assertJsonPath('data.status', 'pending');
    $this->putJson($path, ['answers' => ['are_you_a_gamer' => false]], $this->headers)->assertOk()->assertJsonPath('data.answers.are_you_a_gamer', false);
    $this->getJson('/api/integrations/v1/me/membership-requests', $this->headers)->assertOk()->assertJsonCount(1, 'data');
});

it('returns rendered localized notification text and protects notification ownership', function () {
    $this->patchJson('/api/integrations/v1/me/name', ['username' => 'Notification test'], $this->headers)->assertOk();
    $result = $this->getJson('/api/integrations/v1/me/notifications', [...$this->headers, 'Accept-Language' => 'de'])->assertOk();
    $item = $result->json('items.0');
    expect($item['title'])->not->toContain('notifications.')->and($item['source'])->toBe('user');
    $this->postJson('/api/integrations/v1/me/notifications/'.$item['source_id'].'/read', [], $this->headers)->assertOk();
    $this->getJson('/api/integrations/v1/me/notifications/summary', $this->headers)->assertOk()->assertJsonPath('unread_count', 0);
    $other = User::factory()->create();
    $this->link->update(['user_id' => $other->id]);
    $this->postJson('/api/integrations/v1/me/notifications/'.$item['source_id'].'/read', [], $this->headers)->assertNotFound();
});

it('keeps every permission in exactly one broader group without changing stored grants', function () {
    $scopes = collect(IntegrationPermissions::scopeGroups())->pluck('permissions')->flatten()->all();
    expect(count($scopes))->toBe(count(array_unique($scopes)));
    expect(collect($scopes)->sort()->values()->all())->toBe(collect(IntegrationPermissions::scopes())->sort()->values()->all());
    $events = collect(IntegrationPermissions::eventGroups())->pluck('permissions')->flatten()->all();
    expect(count($events))->toBe(count(array_unique($events)));
    $client = IntegrationClient::factory()->create(['scopes' => [IntegrationClient::SCOPE_RUNS_READ], 'allowed_events' => [IntegrationClient::EVENT_DISCORD_GUILD_RUN_COMPLETED]]);
    expect($client->fresh()->scopes)->toBe([IntegrationClient::SCOPE_RUNS_READ]);
    expect($client->fresh()->allowed_events)->toBe([IntegrationClient::EVENT_DISCORD_GUILD_RUN_COMPLETED]);
});
