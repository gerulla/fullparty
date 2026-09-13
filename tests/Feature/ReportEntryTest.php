<?php

use App\Models\Activity;
use App\Models\Group;
use App\Models\GroupUserNote;
use App\Models\User;
use App\Support\ReportUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('remembers the selected report target through login without exposing content to guests', function () {
    $group = Group::factory()->create(['is_visible' => true]);
    $url = route('reports.create', ['type' => 'group', 'id' => $group->id]);
    $this->get($url)->assertRedirect(route('login'))->assertSessionHas('url.intended', $url);
    $this->actingAs(User::factory()->create())->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reports/Create')->where('target.type', 'group')->where('target.id', $group->id)->where('target.label', $group->name));
    $this->assertDatabaseCount('content_reports', 0);
});

it('does not reveal inaccessible or unsupported report targets through the entry page', function () {
    $group = Group::factory()->create(['is_visible' => false]);
    $this->actingAs(User::factory()->create());
    $this->get(route('reports.create', ['type' => 'group', 'id' => $group->id]))->assertNotFound();
    $this->get(route('reports.create', ['type' => 'unknown', 'id' => $group->id]))->assertNotFound();
    $this->get(route('reports.create', ['type' => 'group', 'id' => 999999]))->assertNotFound();
});

it('builds public reporting links on the main app host with the current locale', function () {
    config(['app.url' => 'https://fullparty.test']);
    app()->setLocale('de');
    expect(ReportUrl::for('upload', 12))->toBe('https://fullparty.test/de/reports/new/upload/12');
});

it('allows reporting shared notes only from an existing authorized review context', function () {
    $subject = User::factory()->create();
    $viewer = User::factory()->create();
    $source = Group::factory()->create();
    $reviewGroup = Group::factory()->create(['owner_id' => $viewer->id]);
    $note = GroupUserNote::create(['group_id' => $source->id, 'user_id' => $subject->id,
        'author_user_id' => $source->owner_id, 'body' => 'Shared moderation note', 'severity' => 'info', 'is_shared_with_groups' => true]);
    $url = route('reports.create', ['type' => 'member_note', 'id' => $note->id]);
    $this->actingAs($viewer)->get($url)->assertNotFound();
    $reviewGroup->memberships()->create(['user_id' => $subject->id, 'role' => 'member', 'joined_at' => now()]);
    $this->get($url)->assertOk();
    $note->update(['is_shared_with_groups' => false]);
    $this->get($url)->assertNotFound();
    $note->update(['is_shared_with_groups' => true]);
    $this->actingAs($subject)->get($url)->assertNotFound();
    $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
});

it('allows shared note reports while reviewing an applicant who is not a group member', function () {
    $subject = User::factory()->create();
    $viewer = User::factory()->create();
    $source = Group::factory()->create();
    $reviewGroup = Group::factory()->create(['owner_id' => $viewer->id]);
    $activity = Activity::factory()->create(['group_id' => $reviewGroup->id]);
    $activity->applications()->create(['user_id' => $subject->id, 'status' => 'pending']);
    $note = GroupUserNote::create(['group_id' => $source->id, 'user_id' => $subject->id,
        'author_user_id' => $source->owner_id, 'body' => 'Shared note', 'severity' => 'info', 'is_shared_with_groups' => true]);
    $this->actingAs($viewer)->get(route('reports.create', ['type' => 'member_note', 'id' => $note->id]))->assertOk();
});
