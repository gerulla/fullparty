<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityGroupFollowUp;
use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\User;
use App\Services\Runs\RunAccessService;
use App\Support\Activities\ActivityDisplayName;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ActivityGroupFollowUpService
{
    public function __construct(
        private readonly ActivityFollowUpParticipants $participants,
        private readonly GroupPersonalInvitationService $invitations,
        private readonly RunAccessService $access,
    ) {}

    public function prepare(Activity $activity, int $completedBy): void
    {
        DB::transaction(function () use ($activity, $completedBy) {
            $activity = Activity::query()->lockForUpdate()->findOrFail($activity->id);
            if ($activity->status !== Activity::STATUS_COMPLETE || $activity->group_follow_up_prepared_at !== null) {
                return;
            }
            $group = $activity->group->load(['memberships', 'bans']);
            $kind = $group->allowsOpenJoin() ? ActivityGroupFollowUp::JOIN_GROUP : ActivityGroupFollowUp::INVITE_CANDIDATE;
            $attendees = $this->participants->attendees($activity)
                ->filter(fn (array $entry) => $this->participants->canJoin($group, $entry['user']));
            foreach ($attendees as $entry) {
                ActivityGroupFollowUp::query()->create([
                    'activity_id' => $activity->id, 'user_id' => $entry['user']->id, 'kind' => $kind,
                    'characters' => $entry['characters'], 'assignment_ids' => $entry['assignment_ids'],
                ]);
            }

            if ($kind === ActivityGroupFollowUp::INVITE_CANDIDATE && $attendees->isNotEmpty()) {
                $hosts = $activity->slots()->where('is_host', true)->with('assignedCharacter')->get()
                    ->pluck('assignedCharacter.user_id')->push($completedBy, $activity->organized_by_user_id)->filter()->unique();
                foreach (User::query()->whereIn('id', $hosts)->whereNull('banned_at')->get() as $host) {
                    if ($group->hasModeratorAccess($host->id) && ! $group->isBanned($host->id)) {
                        ActivityGroupFollowUp::query()->create([
                            'activity_id' => $activity->id, 'user_id' => $host->id, 'kind' => ActivityGroupFollowUp::INVITE_PARTICIPANTS,
                        ]);
                    }
                }
            }
            $activity->forceFill(['group_follow_up_prepared_at' => now()])->save();
        });
    }

    public function forRun(Group $group, Activity $activity, User $viewer): ?array
    {
        abort_unless($this->access->canView($group, $activity, $viewer->id), 404);
        if ($activity->status !== Activity::STATUS_COMPLETE || $activity->group_follow_up_prepared_at === null) {
            return null;
        }
        if ($group->join_mode === Group::JOIN_MODE_INVITE_ONLY && $group->hasModeratorAccess($viewer->id)) {
            $this->invitations->authorizeSender($group, $viewer);
            $candidates = $this->participants->eligible($activity, ActivityGroupFollowUp::INVITE_CANDIDATE);
            $invites = $group->invites()->whereIn('recipient_user_id', $candidates->keys())->latest('id')->get()->unique('recipient_user_id')->keyBy('recipient_user_id');
            $declinedIds = $group->invites()->where('source_activity_id', $activity->id)
                ->where('status', GroupInvite::STATUS_DECLINED)->pluck('recipient_user_id');
            $candidates = $candidates->map(function (ActivityGroupFollowUp $entry) use ($invites, $declinedIds) {
                $invite = $invites->get($entry->user_id);

                return [
                    'user_id' => $entry->user_id, 'characters' => $entry->characters,
                    'can_invite' => ! $declinedIds->contains($entry->user_id) && ! $invite?->canBeAccepted(),
                    'invitation' => $invite ? $this->invitationSummary($invite) : null,
                ];
            })->values();
            $prompt = $this->prompt($activity, $viewer, ActivityGroupFollowUp::INVITE_PARTICIPANTS);

            return [...$this->summary($activity, ActivityGroupFollowUp::INVITE_PARTICIPANTS, $prompt),
                'should_prompt' => $this->unseen($prompt) && $candidates->contains('can_invite', true),
                'candidates' => $candidates->all(),
                'send_url' => route('groups.activities.follow-up.invites.store', [$group, $activity]),
            ];
        }
        if ($group->allowsOpenJoin()) {
            $prompt = $this->participants->eligible($activity, ActivityGroupFollowUp::JOIN_GROUP, $viewer->id)->get($viewer->id);
            if ($prompt) {
                return [...$this->summary($activity, ActivityGroupFollowUp::JOIN_GROUP, $prompt),
                    'should_prompt' => $this->unseen($prompt),
                    'join_url' => route('groups.join', $group),
                ];
            }
        }

        return null;
    }

    public function pendingFor(User $viewer): array
    {
        return ActivityGroupFollowUp::query()->with('activity.group')
            ->where('user_id', $viewer->id)->whereIn('kind', [ActivityGroupFollowUp::JOIN_GROUP, ActivityGroupFollowUp::INVITE_PARTICIPANTS])
            ->whereNull('presented_at')->whereNull('dismissed_at')->latest('id')->get()
            ->filter(fn ($entry) => $this->access->canView($entry->activity->group, $entry->activity, $viewer->id))
            ->map(fn ($entry) => $this->forRun($entry->activity->group, $entry->activity, $viewer))
            ->filter(fn ($summary) => $summary !== null && $summary['should_prompt'])
            ->take(20)->values()->all();
    }

    /** Returns true to the first tab that claims the automatic prompt. GET never consumes it. */
    public function acknowledge(ActivityGroupFollowUp $prompt, User $viewer, bool $dismiss = false): bool
    {
        abort_unless((int) $prompt->user_id === (int) $viewer->id && $prompt->kind !== ActivityGroupFollowUp::INVITE_CANDIDATE, 404);
        $summary = $this->forRun($prompt->activity->group, $prompt->activity, $viewer);
        abort_unless($summary !== null && $summary['prompt_id'] === $prompt->id, 404);
        if (! $dismiss && ! $summary['should_prompt']) {
            return false;
        }

        return DB::transaction(function () use ($prompt, $dismiss) {
            $prompt = ActivityGroupFollowUp::query()->lockForUpdate()->findOrFail($prompt->id);
            $unseen = $this->unseen($prompt);
            $prompt->update([
                'presented_at' => $prompt->presented_at ?? now(),
                'dismissed_at' => $dismiss ? ($prompt->dismissed_at ?? now()) : $prompt->dismissed_at,
            ]);

            return $unseen;
        });
    }

    public function send(Group $group, Activity $activity, User $actor, array $recipientIds): Collection
    {
        abort_unless((int) $activity->group_id === (int) $group->id, 404);
        $this->invitations->authorizeSender($group, $actor);

        return DB::transaction(function () use ($group, $activity, $actor, $recipientIds) {
            $activity = Activity::query()->lockForUpdate()->findOrFail($activity->id);
            abort_unless($activity->status === Activity::STATUS_COMPLETE && $group->join_mode === Group::JOIN_MODE_INVITE_ONLY, 404);
            $candidates = $this->participants->eligible($activity, ActivityGroupFollowUp::INVITE_CANDIDATE);
            foreach ($recipientIds as $id) {
                if (! $candidates->has($id)) {
                    throw ValidationException::withMessages(['user_ids' => __('group_invitations.ineligible')]);
                }
            }
            $invites = collect($recipientIds)->unique()->values()->map(fn ($id) => $this->invitations->send($group, $candidates->get($id)->user, $actor, $activity));
            ActivityGroupFollowUp::query()->updateOrCreate([
                'activity_id' => $activity->id, 'user_id' => $actor->id, 'kind' => ActivityGroupFollowUp::INVITE_PARTICIPANTS,
            ], ['presented_at' => now(), 'dismissed_at' => now()]);

            return $invites->map(fn ($invite) => $this->invitationSummary($invite));
        });
    }

    public function invitationSummary(GroupInvite $invite): array
    {
        return ['id' => $invite->id, 'recipient_user_id' => $invite->recipient_user_id,
            'status' => $invite->status === GroupInvite::STATUS_PENDING && $invite->isExpired() ? GroupInvite::STATUS_EXPIRED : $invite->status,
            'expires_at' => $invite->expires_at?->toIso8601String()];
    }

    private function prompt(Activity $activity, User $viewer, string $kind): ?ActivityGroupFollowUp
    {
        return ActivityGroupFollowUp::query()->where('activity_id', $activity->id)->where('user_id', $viewer->id)->where('kind', $kind)->first();
    }

    private function unseen(?ActivityGroupFollowUp $prompt): bool
    {
        return $prompt !== null && $prompt->presented_at === null && $prompt->dismissed_at === null;
    }

    private function summary(Activity $activity, string $kind, ?ActivityGroupFollowUp $prompt): array
    {
        return [
            'kind' => $kind, 'prompt_id' => $prompt?->id,
            'presented_at' => $prompt?->presented_at?->toIso8601String(),
            'dismissed_at' => $prompt?->dismissed_at?->toIso8601String(),
            'group' => $activity->group->only(['id', 'name', 'slug', 'profile_picture_url']),
            'run' => ['id' => $activity->id, 'title' => ActivityDisplayName::for($activity), 'completed_at' => $activity->completed_at?->toIso8601String()],
            'presented_url' => $prompt ? route('account.run-follow-ups.presented', $prompt) : null,
            'dismiss_url' => $prompt ? route('account.run-follow-ups.dismiss', $prompt) : null,
        ];
    }
}
