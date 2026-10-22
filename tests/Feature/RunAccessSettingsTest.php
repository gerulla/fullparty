<?php

use App\Models\Activity;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
});

it('rejects audience and guest setting changes atomically including drafts and JSON requests', function (string $field, bool $initial, string $status) {
    $run = Activity::factory()->create(['status' => $status, 'is_public' => true, $field => $initial]);
    $title = $run->title;
    $this->actingAs($run->group->owner)->putJson(route('groups.dashboard.activities.update', [$run->group, $run]), [
        'title' => 'Should not be saved', $field => ! $initial,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($run->fresh()->{$field})->toBe($initial)
        ->and($run->fresh()->title)->toBe($title)
        ->and(AuditLog::where('action', 'group.activity.updated')->count())->toBe(0);
})->with(['is_public', 'allow_guest_applications'])->with([true, false])
    ->with([Activity::STATUS_DRAFT, Activity::STATUS_SCHEDULED, Activity::STATUS_ASSIGNED, Activity::STATUS_ONGOING]);

it('accepts unchanged or omitted settings while editing other fields', function (bool $include, bool $initial) {
    $run = Activity::factory()->create(['status' => Activity::STATUS_SCHEDULED, 'is_public' => $initial, 'allow_guest_applications' => $initial]);
    $input = ['title' => 'Updated title'];
    if ($include) {
        $input += ['is_public' => $initial ? '1' : '0', 'allow_guest_applications' => $initial ? 1 : 0];
    }
    $this->actingAs($run->group->owner)->putJson(route('groups.dashboard.activities.update', [$run->group, $run]), $input)->assertRedirect();
    expect($run->fresh()->title)->toBe('Updated title')->and($run->fresh()->is_public)->toBe($initial)
        ->and($run->fresh()->allow_guest_applications)->toBe($initial);
})->with([true, false])->with([true, false]);

it('allows new copies to choose their own access settings without changing their source', function (bool $public, bool $guests) {
    $run = Activity::factory()->create(['status' => Activity::STATUS_SCHEDULED, 'is_public' => ! $public, 'allow_guest_applications' => false]);
    $this->actingAs($run->group->owner)->postJson(route('groups.dashboard.activities.duplicate', [$run->group, $run]), [
        'title' => 'New copy', 'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'), 'status' => Activity::STATUS_DRAFT,
        'copy_bench' => false, 'copy_fill_ins' => false, 'is_public' => $public, 'allow_guest_applications' => $guests,
    ])->assertRedirect();
    $copy = $run->group->activities()->whereKeyNot($run->id)->sole();
    expect($copy->is_public)->toBe($public)->and($copy->allow_guest_applications)->toBe($public && $guests)
        ->and($run->fresh()->is_public)->toBe(! $public)->and($run->fresh()->allow_guest_applications)->toBeFalse();
})->with([true, false])->with([true, false]);

it('allows creating guest-enabled direct-assignment runs', function () {
    $run = Activity::factory()->create(['status' => Activity::STATUS_SCHEDULED]);
    $this->actingAs($run->group->owner)->postJson(route('groups.dashboard.activities.store', [$run->group]), [
        'activity_type_id' => $run->activity_type_id, 'status' => Activity::STATUS_DRAFT,
        'needs_application' => false, 'is_public' => true, 'allow_guest_applications' => true,
    ])->assertRedirect();
    $created = $run->group->activities()->whereKeyNot($run->id)->sole();
    expect($created->needs_application)->toBeFalse()->and($created->allow_guest_applications)->toBeTrue();
});
