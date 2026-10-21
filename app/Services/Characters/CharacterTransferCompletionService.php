<?php

namespace App\Services\Characters;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\Character;
use App\Models\CharacterTransfer;
use App\Models\CharacterTransferRun;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Groups\ActivityOverviewAccessService;
use App\Services\Groups\ActivitySlotAttendanceService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CharacterTransferCompletionService
{
    public function __construct(
        private readonly CharacterTransferRunService $runTransfers,
        private readonly ActivityOverviewAccessService $access,
        private readonly AuditLogger $audit,
        private readonly ActivitySlotAttendanceService $attendance,
    ) {}

    /** Called within the ownership transaction, before the character changes account. */
    public function begin(Character $character, User $recipient, string $method): void
    {
        $transfer = CharacterTransfer::create([
            'character_id' => $character->id, 'from_user_id' => $character->user_id,
            'to_user_id' => $recipient->id, 'method' => $method,
        ]);
        Activity::query()->where(fn ($query) => $query
            ->whereHas('applications', fn ($apps) => $apps->where('selected_character_id', $character->id)
                ->where('user_id', $character->user_id))
            ->orWhereHas('slots', fn ($slots) => $slots->where('assigned_character_id', $character->id))
            ->orWhereHas('slotAssignments', fn ($assignments) => $assignments->where('character_id', $character->id)))
            ->orderBy('id')->lockForUpdate()->get()->each(function (Activity $activity) use ($character, $transfer) {
                $history = $activity->isArchived() || $activity->is_completed
                    || ($activity->starts_at && $activity->starts_at->isPast());
                $application = $activity->applications()->where('selected_character_id', $character->id)
                    ->where('user_id', $character->user_id)->orderByDesc('id')->first();
                $slots = $activity->slots()->where('assigned_character_id', $character->id)->pluck('id')->all();
                if (! $history && $slots === [] && ! in_array($application?->status, ActivityApplication::ACTIVE_STATUSES, true)) {
                    return;
                }
                if (! $history) {
                    foreach ($activity->slots()->whereIn('id', $slots)->get() as $slot) {
                        if (! $slot->assignments()->where('character_id', $character->id)->whereNull('ended_at')->exists()) {
                            $this->attendance->moveOrCreateActiveAssignment($slot, $character->id,
                                $application?->id, $slot->assigned_by_user_id,
                                $this->attendance->buildFieldValueSnapshot($slot));
                        }
                    }
                }
                $transfer->runs()->create([
                    'character_id' => $character->id, 'activity_id' => $activity->id,
                    'application_id' => $application?->id, 'category' => $history ? 'history' : 'upcoming',
                    'slot_ids' => $slots,
                    'assignment_ids' => $activity->slotAssignments()->where('character_id', $character->id)->pluck('id')->all(),
                ]);
            });
    }

    public function pending(Character $character, User $user): ?CharacterTransfer
    {
        if ((int) $character->user_id !== (int) $user->id) {
            return null;
        }

        return CharacterTransfer::where('character_id', $character->id)->where('to_user_id', $user->id)
            ->whereNull('completed_at')->latest('id')->first();
    }

    public function summary(CharacterTransfer $transfer, User $user): array
    {
        $transfer->loadMissing(['runs.activity.group', 'runs.activity.activityTypeVersion']);

        return [
            'id' => $transfer->id,
            'pending' => $transfer->completed_at === null,
            'method' => $transfer->method,
            'history_choice' => $transfer->history_choice,
            'upcoming_choice' => $transfer->upcoming_choice,
            'runs' => $transfer->runs->filter(fn ($run) => $run->activity !== null)->map(function (CharacterTransferRun $run) use ($user) {
                $activity = $run->activity;

                return [
                    'id' => $run->id, 'category' => $run->category,
                    'title' => $activity->title,
                    'activity_type' => $activity->activityTypeVersion?->name,
                    'group' => $activity->group->name,
                    'starts_at' => $activity->starts_at?->toIso8601String(),
                    'url' => $this->access->allows($activity->group, $activity, $user->id)
                        ? route('groups.activities.overview', ['group' => $activity->group->slug, 'activity' => $activity->id], false)
                        : null,
                ];
            })->values()->all(),
        ];
    }

    public function complete(CharacterTransfer $transfer, User $user, string $history, string $upcoming): void
    {
        DB::transaction(function () use ($transfer, $user, $history, $upcoming) {
            // Follow the ownership workflow's account -> character lock order.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $character = Character::whereKey($transfer->character_id)->lockForUpdate()->firstOrFail();
            $transfer = CharacterTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $transfer->to_user_id === (int) $user->id && (int) $character->user_id === (int) $user->id, 404);
            if ($transfer->completed_at !== null) {
                if ($transfer->history_choice !== $history || $transfer->upcoming_choice !== $upcoming) {
                    throw ValidationException::withMessages(['transfer' => 'already_completed']);
                }

                return;
            }
            foreach ($transfer->runs()->orderBy('activity_id')->get() as $run) {
                $activity = Activity::whereKey($run->activity_id)->lockForUpdate()->first();
                if (! $activity) {
                    continue;
                }
                $choice = $run->category === 'history' ? $history : $upcoming;
                if ($run->category === 'upcoming') {
                    $this->runTransfers->apply($run, $transfer, $character, $user, $upcoming);
                    if ($activity->isArchived() || $activity->is_completed || $activity->starts_at?->isPast()) {
                        $run->category = 'history';
                    }
                }
                $run->fill(['choice' => $choice])->save();
            }
            $transfer->update(['history_choice' => $history, 'upcoming_choice' => $upcoming, 'completed_at' => now()]);
            $this->audit->log(
                action: 'character.transfer_completed', severity: AuditSeverity::SEVERE_CHANGE,
                scopeType: AuditScope::CHARACTER, scopeId: $character->id,
                message: 'characters.transfer.audit_completed', actor: $user, subject: $character,
                metadata: [
                    'transfer_id' => $transfer->id, 'from_user_id' => $transfer->from_user_id,
                    'to_user_id' => $user->id, 'history_choice' => $history, 'upcoming_choice' => $upcoming,
                ],
            );
        }, 3);
    }
}
