<?php

use App\Models\Activity;
use App\Models\Group;
use App\Models\User;
use App\Services\Runs\RunAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps single-run permissions and query filtering equivalent across the access matrix', function (string $role, bool $legacyHidden) {
    $owner = User::factory()->create();
    $viewer = $role === 'guest' ? null : ($role === 'owner' || $role === 'banned_owner' ? $owner : User::factory()->create());
    $group = Group::factory()->create([
        'owner_id' => $owner->id,
        'join_mode' => $legacyHidden ? Group::JOIN_MODE_INVITE_ONLY : Group::JOIN_MODE_OPEN,
        'is_visible' => ! $legacyHidden,
    ]);
    if (in_array($role, ['member', 'admin', 'moderator', 'banned_member'], true)) {
        $group->memberships()->create([
            'user_id' => $viewer->id,
            'role' => $role === 'banned_member' ? 'member' : $role,
            'joined_at' => now(),
        ]);
    }
    if (str_starts_with($role, 'banned_')) {
        $group->bans()->create(['user_id' => $viewer->id, 'banned_by_user_id' => $owner->id]);
    }
    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'organized_by_user_id' => in_array($role, ['organizer', 'banned_organizer'], true) ? $viewer->id : $owner->id,
    ]);
    $access = app(RunAccessService::class);

    foreach (Activity::STATUSES as $status) {
        foreach ([false, true] as $public) {
            foreach ([false, true] as $guests) {
                $activity->update(['status' => $status, 'is_public' => $public, 'allow_guest_applications' => $guests]);
                $draft = $status === Activity::STATUS_DRAFT;
                $canView = ! str_starts_with($role, 'banned_') && ($draft
                    ? in_array($role, ['owner', 'admin', 'moderator', 'organizer'], true)
                    : ($public || in_array($role, ['owner', 'admin', 'moderator', 'member', 'organizer'], true)));
                $canParticipate = $canView && ($viewer !== null || $guests);

                expect($access->canView($group, $activity, $viewer?->id))->toBe($canView)
                    ->and($access->scopeVisibleTo(Activity::query(), $viewer?->id)->whereKey($activity->id)->exists())->toBe($canView)
                    ->and($access->scopeVisibleTo(Activity::query(), $viewer?->id, includeDrafts: false)->whereKey($activity->id)->exists())->toBe($canView && ! $draft)
                    ->and($access->canParticipate($group, $activity, $viewer?->id))->toBe($canParticipate);

                $activity->needs_application = true;
                expect($access->canApply($group, $activity, $viewer?->id))->toBe($canParticipate && in_array($status, Activity::APPLICATION_OPEN_STATUSES, true))
                    ->and($access->canSelfAssign($group, $activity, $viewer?->id))->toBeFalse();
                $activity->needs_application = false;
                expect($access->canSelfAssign($group, $activity, $viewer?->id))->toBe($canParticipate && ! in_array($status, Activity::ARCHIVED_STATUSES, true))
                    ->and($access->canApply($group, $activity, $viewer?->id))->toBeFalse();
            }
        }
    }
})->with(['guest', 'outsider', 'owner', 'admin', 'moderator', 'member', 'organizer', 'banned_member', 'banned_owner', 'banned_organizer'])
    ->with([false, true]);

it('does not grant access through a mismatched group or leak query branches past existing constraints', function () {
    $viewer = User::factory()->create();
    $ownGroup = Group::factory()->create(['owner_id' => $viewer->id]);
    $otherGroup = Group::factory()->create();
    $ownDraft = Activity::factory()->create(['group_id' => $ownGroup->id, 'status' => Activity::STATUS_DRAFT]);
    $otherRun = Activity::factory()->create(['group_id' => $otherGroup->id, 'status' => Activity::STATUS_SCHEDULED, 'is_public' => true]);
    $access = app(RunAccessService::class);

    expect($access->canView($ownGroup, $otherRun, $viewer->id))->toBeFalse()
        ->and($access->canParticipate($ownGroup, $otherRun, $viewer->id))->toBeFalse()
        ->and($access->scopeVisibleTo(Activity::where('group_id', $otherGroup->id), $viewer->id)->pluck('id')->all())->toBe([$otherRun->id])
        ->and($ownGroup->activities()->tap(fn ($query) => $access->scopeVisibleTo($query, $viewer->id))->pluck('id')->all())->toBe([$ownDraft->id]);
});
