<?php

namespace App\Services\Notifications;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\Group;
use App\Models\IntegrationClient;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Services\Integrations\IntegrationWebhookDispatcher;
use App\Support\Activities\ActivityDisplayName;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationChannel;
use App\Support\Notifications\NotificationTopic;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RunNotificationService
{
    private const STARTING_SOON_FLAG = 'run_notification_starting_soon_sent_at';

    private const STARTING_NOW_FLAG = 'run_notification_starting_now_sent_at';

    private const STARTING_SOON_MINUTES = 60;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly IntegrationWebhookDispatcher $webhookDispatcher,
    ) {}

    /**
     * @param  Collection<int, ActivityApplication>|null  $applications
     * @param  Collection<int, ActivityApplication>|null  $placedApplications
     */
    public function notifyCancelled(
        Activity $activity,
        mixed $actor,
        ?Collection $applications = null,
        ?string $reason = null,
        ?Collection $placedApplications = null,
    ): void {
        $recipients = $applications instanceof Collection
            ? $this->mergeRecipients(
                $this->recipientsFromApplications($applications),
                $this->assignedSlotRecipients($activity),
            )
            : $this->activeRunRecipients($activity);

        $this->sendRunNotification(
            activity: $activity,
            recipients: $recipients,
            type: 'runs.cancelled',
            titleKey: 'notifications.runs.cancelled.title',
            bodyKey: filled($reason)
                ? 'notifications.runs.cancelled.body_with_reason'
                : 'notifications.runs.cancelled.body',
            actor: $actor,
            messageParams: filled($reason) ? ['reason' => $reason] : [],
            payload: filled($reason) ? ['reason' => $reason] : [],
        );

        $this->sendDiscordGuildRunCancelled($activity, $placedApplications, $reason);
    }

    public function notifyCompleted(Activity $activity, mixed $actor): void
    {
        $this->sendRunNotification(
            activity: $activity,
            recipients: $this->placedRunRecipients($activity),
            type: 'runs.completed',
            titleKey: 'notifications.runs.completed.title',
            bodyKey: 'notifications.runs.completed.body',
            actor: $actor,
            payload: [
                'completion' => $this->completionPayload($activity),
            ],
        );

        $this->sendDiscordGuildRunCompleted($activity);
    }

    /**
     * @return array{starting_soon: int, starting_now: int}
     */
    public function dispatchDueReminders(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $soonCutoff = $now->addMinutes(self::STARTING_SOON_MINUTES);

        $startingSoonCount = 0;

        Activity::query()
            ->with(['group.activeDiscordGuildIntegration', 'applications.user', 'applications.selectedCharacter'])
            ->whereIn('status', [
                Activity::STATUS_ASSIGNED,
                Activity::STATUS_UPCOMING,
                Activity::STATUS_ONGOING,
            ])
            ->whereNotNull('starts_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $soonCutoff)
            ->orderBy('starts_at')
            ->get()
            ->each(function (Activity $activity) use ($now, &$startingSoonCount): void {
                if (! $this->claimReminder($activity, self::STARTING_SOON_FLAG, $now)) {
                    return;
                }

                $this->notifyStartingSoon($activity);
                $startingSoonCount++;
            });

        $startingNowCount = 0;

        Activity::query()
            ->with(['group.activeDiscordGuildIntegration', 'applications.user', 'applications.selectedCharacter'])
            ->whereIn('status', [
                Activity::STATUS_ASSIGNED,
                Activity::STATUS_UPCOMING,
                Activity::STATUS_ONGOING,
            ])
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', $now)
            ->orderBy('starts_at')
            ->get()
            ->each(function (Activity $activity) use ($now, &$startingNowCount): void {
                if (! $this->claimReminder($activity, self::STARTING_NOW_FLAG, $now)) {
                    return;
                }

                $this->notifyStartingNow($activity);
                $startingNowCount++;
            });

        return [
            'starting_soon' => $startingSoonCount,
            'starting_now' => $startingNowCount,
        ];
    }

    private function notifyStartingSoon(Activity $activity): void
    {
        $recipients = $this->placedRunRecipients($activity);

        $this->sendRunNotification(
            activity: $activity,
            recipients: $recipients,
            type: 'runs.starting_soon',
            titleKey: 'notifications.runs.starting_soon.title',
            bodyKey: 'notifications.runs.starting_soon.body',
        );

        $this->sendDiscordGuildRunReminder($activity, 'runs.starting_soon');
    }

    private function notifyStartingNow(Activity $activity): void
    {
        $recipients = $this->placedRunRecipients($activity);

        $this->sendRunNotification(
            activity: $activity,
            recipients: $recipients,
            type: 'runs.starting_now',
            titleKey: 'notifications.runs.starting_now.title',
            bodyKey: 'notifications.runs.starting_now.body',
        );

        $this->sendDiscordGuildRunReminder($activity, 'runs.starting_now');
    }

    private function createEvent(
        Activity $activity,
        string $type,
        string $titleKey,
        string $bodyKey,
        mixed $actor = null,
        array $messageParams = [],
        array $payload = [],
    ): NotificationEvent {
        $activity->loadMissing('group');

        return $this->notificationService->createEvent(
            type: $type,
            category: NotificationCategory::RUNS_AND_REMINDERS,
            titleKey: $titleKey,
            bodyKey: $bodyKey,
            messageParams: array_merge([
                'activity' => $this->activityTitle($activity),
                'group' => $activity->group?->name,
            ], $messageParams),
            actionUrl: $this->activityOverviewUrl($activity),
            actor: $actor instanceof User ? $actor : null,
            subject: $activity,
            payload: array_merge([
                'activity_id' => $activity->id,
                'group_id' => $activity->group?->id,
                'group_slug' => $activity->group?->slug,
                'activity_title' => $this->activityTitle($activity),
                'status' => $activity->status,
                'starts_at' => $activity->starts_at?->toIso8601String(),
            ], $payload),
            topic: str_starts_with($type, 'runs.starting_')
                ? NotificationTopic::RUNS_REMINDERS
                : NotificationTopic::RUNS_LIFECYCLE,
            groupId: $activity->group?->id,
        );
    }

    /**
     * @param  EloquentCollection<int, User>  $recipients
     */
    private function sendRunNotification(
        Activity $activity,
        EloquentCollection $recipients,
        string $type,
        string $titleKey,
        string $bodyKey,
        mixed $actor = null,
        array $messageParams = [],
        array $payload = [],
    ): void {
        if ($recipients->isEmpty()) {
            return;
        }

        $event = $this->createEvent(
            activity: $activity,
            type: $type,
            titleKey: $titleKey,
            bodyKey: $bodyKey,
            actor: $actor,
            messageParams: $messageParams,
            payload: $payload,
        );

        $this->notificationService->sendInAppNotifications($event, $recipients);
        $this->notificationService->sendOffSiteNotifications($event, $recipients, [
            NotificationChannel::EMAIL,
            NotificationChannel::DISCORD,
        ]);
    }

    private function sendDiscordGuildRunReminder(
        Activity $activity,
        string $notificationType,
    ): void {
        $activity->loadMissing([
            'activityTypeVersion',
            'group.activeDiscordGuildIntegration',
            'group.memberships',
            'slots.assignedCharacter.user.discordUserIntegration',
            'slots.assignedCharacter.user.primaryCharacter',
            'applications.user.discordUserIntegration',
            'applications.user.primaryCharacter',
            'applications.selectedCharacter',
        ]);

        $group = $activity->group;
        $guildIntegration = $group?->activeDiscordGuildIntegration;

        if (! $group || ! $guildIntegration) {
            return;
        }

        $rosterParticipants = $this->placedRunParticipantEntries($activity)
            ->map(fn (array $entry): array => $this->serializeDiscordGuildParticipant($entry, $group));

        $participants = $rosterParticipants
            ->filter(fn (array $participant): bool => filled($participant['discord_user_id'] ?? null))
            ->unique('discord_user_id')
            ->values();
        $unlinkedParticipants = $rosterParticipants
            ->filter(fn (array $participant): bool => blank($participant['discord_user_id'] ?? null))
            ->values();

        if ($rosterParticipants->isEmpty()) {
            return;
        }

        $this->webhookDispatcher->dispatchDiscordBotEvent(
            IntegrationClient::EVENT_DISCORD_GUILD_RUN_REMINDER,
            [
                'type' => $notificationType,
                'reminder_type' => str_replace('runs.', '', $notificationType),
                'run_id' => $activity->id,
                'activity_id' => $activity->activity_type_id,
                'activity_type_id' => $activity->activity_type_id,
                'activity_type_version_id' => $activity->activity_type_version_id,
                'group_id' => $group->id,
                'group_slug' => $group->slug,
                'discord_guild_id' => $guildIntegration->discord_guild_id,
                'discord_user_ids' => $participants
                    ->pluck('discord_user_id')
                    ->filter()
                    ->values()
                    ->all(),
                'participants' => $participants->all(),
                'unlinked_participants' => $unlinkedParticipants->all(),
                'unlinked_count' => $unlinkedParticipants->count(),
                'total_placed_count' => $rosterParticipants->count(),
                'run' => [
                    'id' => $activity->id,
                    'display_name' => $this->activityTitle($activity),
                    'status' => $activity->status,
                    'starts_at' => $activity->starts_at?->toIso8601String(),
                    'url' => $this->activityOverviewUrl($activity),
                    'activity_type' => [
                        'id' => $activity->activity_type_id,
                        'version_id' => $activity->activityTypeVersion?->id,
                        'name' => $activity->activityTypeVersion?->name,
                        'difficulty' => $activity->activityTypeVersion?->difficulty,
                    ],
                ],
                'group' => [
                    'id' => $group->id,
                    'slug' => $group->slug,
                    'name' => $group->name,
                ],
                'discord_guild' => [
                    'id' => $guildIntegration->discord_guild_id,
                    'name' => $guildIntegration->name,
                ],
            ],
            permissionEvent: $this->guildRunReminderPermissionEvent($notificationType),
        );
    }

    private function sendDiscordGuildRunCompleted(Activity $activity): void
    {
        $activity->loadMissing([
            'activityTypeVersion',
            'group.activeDiscordGuildIntegration',
        ]);

        $group = $activity->group;
        $guildIntegration = $group?->activeDiscordGuildIntegration;

        if (! $group || ! $guildIntegration) {
            return;
        }

        $recipients = $this->placedRunUsers($activity);
        $recipients->loadMissing(['discordUserIntegration', 'primaryCharacter']);

        $participants = $recipients
            ->filter(fn (User $user): bool => filled($user->discordUserIntegration?->discord_user_id))
            ->map(fn (User $user): array => [
                'user_id' => $user->id,
                'discord_user_id' => $user->discordUserIntegration?->discord_user_id,
                'primary_character' => [
                    'name' => $user->primaryCharacter?->name,
                    'world' => $user->primaryCharacter?->world,
                ],
            ])
            ->unique('discord_user_id')
            ->values();

        if ($participants->isEmpty()) {
            return;
        }

        $this->webhookDispatcher->dispatchDiscordBotEvent(
            IntegrationClient::EVENT_DISCORD_GUILD_RUN_COMPLETED,
            [
                'type' => 'runs.completed',
                'run_id' => $activity->id,
                'activity_id' => $activity->id,
                'group_id' => $group->id,
                'group_slug' => $group->slug,
                'discord_guild_id' => $guildIntegration->discord_guild_id,
                'discord_user_ids' => $participants
                    ->pluck('discord_user_id')
                    ->filter()
                    ->values()
                    ->all(),
                'participants' => $participants->all(),
                'run' => [
                    'id' => $activity->id,
                    'display_name' => $this->activityTitle($activity),
                    'status' => $activity->status,
                    'starts_at' => $activity->starts_at?->toIso8601String(),
                    'completed_at' => $activity->completed_at?->toIso8601String(),
                    'url' => $this->activityOverviewUrl($activity),
                    'activity_type' => [
                        'id' => $activity->activityTypeVersion?->id,
                        'name' => $activity->activityTypeVersion?->name,
                        'difficulty' => $activity->activityTypeVersion?->difficulty,
                    ],
                ],
                'group' => [
                    'id' => $group->id,
                    'slug' => $group->slug,
                    'name' => $group->name,
                ],
                'discord_guild' => [
                    'id' => $guildIntegration->discord_guild_id,
                    'name' => $guildIntegration->name,
                ],
            ],
        );
    }

    /**
     * @param  Collection<int, ActivityApplication>|null  $placedApplications
     */
    private function sendDiscordGuildRunCancelled(
        Activity $activity,
        ?Collection $placedApplications = null,
        ?string $reason = null,
    ): void {
        $activity->loadMissing([
            'activityTypeVersion',
            'group.activeDiscordGuildIntegration',
        ]);

        $group = $activity->group;
        $guildIntegration = $group?->activeDiscordGuildIntegration;

        if (! $group || ! $guildIntegration) {
            return;
        }

        $recipients = $this->placedRunUsersForCancelledActivity($activity, $placedApplications);
        $recipients->loadMissing(['discordUserIntegration', 'primaryCharacter']);

        $participants = $recipients
            ->filter(fn (User $user): bool => filled($user->discordUserIntegration?->discord_user_id))
            ->map(fn (User $user): array => [
                'user_id' => $user->id,
                'discord_user_id' => $user->discordUserIntegration?->discord_user_id,
                'primary_character' => [
                    'name' => $user->primaryCharacter?->name,
                    'world' => $user->primaryCharacter?->world,
                ],
            ])
            ->unique('discord_user_id')
            ->values();

        if ($participants->isEmpty()) {
            return;
        }

        $this->webhookDispatcher->dispatchDiscordBotEvent(
            IntegrationClient::EVENT_DISCORD_GUILD_RUN_CANCELLED,
            [
                'type' => 'runs.cancelled',
                'run_id' => $activity->id,
                'activity_id' => $activity->id,
                'group_id' => $group->id,
                'group_slug' => $group->slug,
                'discord_guild_id' => $guildIntegration->discord_guild_id,
                'cancellation_reason' => $reason,
                'discord_user_ids' => $participants
                    ->pluck('discord_user_id')
                    ->filter()
                    ->values()
                    ->all(),
                'participants' => $participants->all(),
                'run' => [
                    'id' => $activity->id,
                    'display_name' => $this->activityTitle($activity),
                    'status' => $activity->status,
                    'starts_at' => $activity->starts_at?->toIso8601String(),
                    'cancelled_at' => $activity->updated_at?->toIso8601String(),
                    'url' => $this->activityOverviewUrl($activity),
                    'activity_type' => [
                        'id' => $activity->activityTypeVersion?->id,
                        'name' => $activity->activityTypeVersion?->name,
                        'difficulty' => $activity->activityTypeVersion?->difficulty,
                    ],
                ],
                'group' => [
                    'id' => $group->id,
                    'slug' => $group->slug,
                    'name' => $group->name,
                ],
                'discord_guild' => [
                    'id' => $guildIntegration->discord_guild_id,
                    'name' => $guildIntegration->name,
                ],
            ],
        );
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function activeApplicantRecipients(Activity $activity): EloquentCollection
    {
        return $this->applicantRecipientsForStatuses($activity, [
            ActivityApplication::STATUS_PENDING,
            ActivityApplication::STATUS_APPROVED,
            ActivityApplication::STATUS_ON_BENCH,
        ]);
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function activeRunRecipients(Activity $activity): EloquentCollection
    {
        return $this->mergeRecipients(
            $this->activeApplicantRecipients($activity),
            $this->assignedSlotRecipients($activity),
        );
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function placedApplicantRecipients(Activity $activity): EloquentCollection
    {
        return $this->applicantRecipientsForStatuses($activity, [
            ActivityApplication::STATUS_APPROVED,
            ActivityApplication::STATUS_ON_BENCH,
        ]);
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function placedRunRecipients(Activity $activity): EloquentCollection
    {
        return $this->mergeRecipients(
            $this->placedApplicantRecipients($activity),
            $this->assignedSlotRecipients($activity),
        );
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function placedRunUsers(Activity $activity): EloquentCollection
    {
        return $this->mergeUsers(
            $this->applicantUsersForStatuses($activity, [
                ActivityApplication::STATUS_APPROVED,
                ActivityApplication::STATUS_ON_BENCH,
            ]),
            $this->assignedSlotUsers($activity),
        );
    }

    /**
     * @return Collection<int, array{user: User, character: mixed, source: string}>
     */
    private function placedRunParticipantEntries(Activity $activity): Collection
    {
        $entries = collect();

        $activity->slots
            ->filter(fn (ActivitySlot $slot): bool => $slot->assignedCharacter?->user instanceof User)
            ->each(function (ActivitySlot $slot) use ($entries): void {
                $entries->push([
                    'user' => $slot->assignedCharacter->user,
                    'character' => $slot->assignedCharacter,
                    'source' => 'slot',
                ]);
            });

        $activity->applications
            ->filter(fn (ActivityApplication $application): bool => in_array($application->status, [
                ActivityApplication::STATUS_APPROVED,
                ActivityApplication::STATUS_ON_BENCH,
            ], true))
            ->filter(fn (ActivityApplication $application): bool => $application->user instanceof User)
            ->each(function (ActivityApplication $application) use ($entries): void {
                $entries->push([
                    'user' => $application->user,
                    'character' => $application->selectedCharacter,
                    'source' => 'application',
                ]);
            });

        return $entries
            ->sortBy(fn (array $entry): int => $entry['source'] === 'slot' ? 0 : 1)
            ->unique(fn (array $entry): int => $entry['user']->id)
            ->values();
    }

    /**
     * @param  Collection<int, ActivityApplication>|null  $placedApplications
     * @return EloquentCollection<int, User>
     */
    private function placedRunUsersForCancelledActivity(Activity $activity, ?Collection $placedApplications = null): EloquentCollection
    {
        if ($placedApplications instanceof Collection) {
            return $this->mergeUsers(
                $this->usersFromApplications($placedApplications),
                $this->assignedSlotUsers($activity),
            );
        }

        return $this->placedRunUsers($activity);
    }

    /**
     * @param  Collection<int, ActivityApplication>  $applications
     * @return EloquentCollection<int, User>
     */
    private function recipientsFromApplications(Collection $applications): EloquentCollection
    {
        return new EloquentCollection(
            $applications
                ->map(function (ActivityApplication $application) {
                    $application->loadMissing('user');

                    return $application->user;
                })
                ->filter(fn ($user) => $user instanceof User)
                ->unique('id')
                ->values()
                ->all()
        );
    }

    /**
     * @param  Collection<int, ActivityApplication>  $applications
     * @return EloquentCollection<int, User>
     */
    private function usersFromApplications(Collection $applications): EloquentCollection
    {
        return new EloquentCollection(
            $applications
                ->map(function (ActivityApplication $application) {
                    $application->loadMissing('user');

                    return $application->user;
                })
                ->filter(fn ($user) => $user instanceof User)
                ->unique('id')
                ->values()
                ->all()
        );
    }

    /**
     * @param  array<int, string>  $statuses
     * @return EloquentCollection<int, User>
     */
    private function applicantRecipientsForStatuses(Activity $activity, array $statuses): EloquentCollection
    {
        $activity->loadMissing('applications.user');

        return new EloquentCollection(
            $activity->applications
                ->filter(fn (ActivityApplication $application) => in_array($application->status, $statuses, true))
                ->map(fn (ActivityApplication $application) => $application->user)
                ->filter(fn ($user) => $user instanceof User)
                ->unique('id')
                ->values()
                ->all()
        );
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function assignedSlotRecipients(Activity $activity): EloquentCollection
    {
        $activity->loadMissing('slots.assignedCharacter.user');

        return new EloquentCollection(
            $activity->slots
                ->map(fn (ActivitySlot $slot) => $slot->assignedCharacter?->user)
                ->filter(fn ($user) => $user instanceof User)
                ->unique('id')
                ->values()
                ->all()
        );
    }

    /**
     * @param  array<int, string>  $statuses
     * @return EloquentCollection<int, User>
     */
    private function applicantUsersForStatuses(Activity $activity, array $statuses): EloquentCollection
    {
        $activity->loadMissing('applications.user');

        return new EloquentCollection(
            $activity->applications
                ->filter(fn (ActivityApplication $application) => in_array($application->status, $statuses, true))
                ->map(fn (ActivityApplication $application) => $application->user)
                ->filter(fn ($user) => $user instanceof User)
                ->unique('id')
                ->values()
                ->all()
        );
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function assignedSlotUsers(Activity $activity): EloquentCollection
    {
        $activity->loadMissing('slots.assignedCharacter.user');

        return new EloquentCollection(
            $activity->slots
                ->map(fn (ActivitySlot $slot) => $slot->assignedCharacter?->user)
                ->filter(fn ($user) => $user instanceof User)
                ->unique('id')
                ->values()
                ->all()
        );
    }

    /**
     * @param  EloquentCollection<int, User>  ...$collections
     * @return EloquentCollection<int, User>
     */
    private function mergeRecipients(EloquentCollection ...$collections): EloquentCollection
    {
        return new EloquentCollection(
            collect($collections)
                ->flatMap(fn (EloquentCollection $collection) => $collection->all())
                ->filter(fn ($user) => $user instanceof User)
                ->unique('id')
                ->values()
                ->all()
        );
    }

    /**
     * @param  EloquentCollection<int, User>  ...$collections
     * @return EloquentCollection<int, User>
     */
    private function mergeUsers(EloquentCollection ...$collections): EloquentCollection
    {
        return new EloquentCollection(
            collect($collections)
                ->flatMap(fn (EloquentCollection $collection) => $collection->all())
                ->filter(fn ($user) => $user instanceof User)
                ->unique('id')
                ->values()
                ->all()
        );
    }

    private function activityOverviewUrl(Activity $activity): string
    {
        $activity->loadMissing('group');

        if (! $activity->group) {
            return route('account.applications');
        }

        $parameters = [
            'group' => $activity->group->slug,
            'activity' => $activity->id,
        ];

        return route('groups.activities.overview', $parameters);
    }

    /**
     * @param  array{user: User, character: mixed, source: string}  $entry
     * @return array<string, mixed>
     */
    private function serializeDiscordGuildParticipant(array $entry, Group $group): array
    {
        $user = $entry['user'];
        $character = $entry['character'] ?: $user->primaryCharacter;
        $discordUserId = $this->activeDiscordUserId($user);
        $groupRole = $this->groupRoleForUser($group, $user);

        return [
            'user_id' => $user->id,
            'discord_user_id' => $discordUserId,
            'is_discord_linked' => $discordUserId !== null,
            'is_group_member' => $groupRole !== null,
            'group_role' => $groupRole,
            'source' => $entry['source'],
            'primary_character' => [
                'name' => $character?->name,
                'world' => $character?->world,
            ],
            'character' => $character ? [
                'id' => $character->id,
                'name' => $character->name,
                'world' => $character->world,
                'datacenter' => $character->datacenter,
                'avatar_url' => $character->avatar_url ?? null,
            ] : null,
        ];
    }

    private function activeDiscordUserId(?User $user): ?string
    {
        $integration = $user?->discordUserIntegration;

        if (! $integration || blank($integration->discord_user_id) || $integration->user_app_installed_at === null) {
            return null;
        }

        return $integration->discord_user_id;
    }

    private function groupRoleForUser(Group $group, User $user): ?string
    {
        if ($group->owner_id === $user->id) {
            return 'owner';
        }

        return $group->memberships
            ->firstWhere('user_id', $user->id)
            ?->role;
    }

    /**
     * @return array<string, mixed>
     */
    private function completionPayload(Activity $activity): array
    {
        $activity->loadMissing(['activityTypeVersion', 'progressMilestones']);

        return [
            'completed_at' => $activity->completed_at?->toIso8601String(),
            'progress_recorded_at' => $activity->progress_recorded_at?->toIso8601String(),
            'progress_recorded_by_user_id' => $activity->progress_recorded_by_user_id,
            'progress_entry_mode' => $activity->progress_entry_mode,
            'progress_link_url' => $activity->progress_link_url,
            'progress_notes' => $activity->progress_notes,
            'furthest_progress_key' => $activity->furthest_progress_key,
            'furthest_progress_label' => $this->progressPointLabel($activity, $activity->furthest_progress_key),
            'furthest_progress_percent' => $activity->furthest_progress_percent !== null
                ? (float) $activity->furthest_progress_percent
                : null,
            'milestones' => $activity->progressMilestones
                ->map(fn ($milestone) => [
                    'milestone_key' => $milestone->milestone_key,
                    'milestone_label' => $milestone->milestone_label,
                    'kills' => (int) $milestone->kills,
                    'best_progress_percent' => $milestone->best_progress_percent !== null
                        ? (float) $milestone->best_progress_percent
                        : null,
                    'source' => $milestone->source,
                    'notes' => $milestone->notes,
                ])
                ->values()
                ->all(),
        ];
    }

    private function progressPointLabel(Activity $activity, ?string $key): ?array
    {
        if (blank($key)) {
            return null;
        }

        $milestoneLabel = $activity->progressMilestones
            ->firstWhere('milestone_key', $key)
            ?->milestone_label;

        if (is_array($milestoneLabel)) {
            return $milestoneLabel;
        }

        $progPoint = collect($activity->activityTypeVersion?->prog_points ?? [])
            ->firstWhere('key', $key);
        $progPointLabel = is_array($progPoint) ? ($progPoint['label'] ?? null) : null;

        return is_array($progPointLabel) ? $progPointLabel : null;
    }

    private function activityTitle(Activity $activity): string
    {
        return ActivityDisplayName::for($activity);
    }

    private function guildRunReminderPermissionEvent(string $notificationType): string
    {
        return match ($notificationType) {
            'runs.starting_soon' => IntegrationClient::EVENT_DISCORD_GUILD_RUN_STARTING_SOON,
            'runs.starting_now' => IntegrationClient::EVENT_DISCORD_GUILD_RUN_STARTING_NOW,
            default => IntegrationClient::EVENT_DISCORD_GUILD_RUN_REMINDER,
        };
    }

    private function reminderAlreadySent(Activity $activity, string $key): bool
    {
        return filled(($activity->settings ?? [])[$key] ?? null);
    }

    private function claimReminder(Activity $activity, string $key, CarbonImmutable $timestamp): bool
    {
        return DB::transaction(function () use ($activity, $key, $timestamp): bool {
            $lockedActivity = Activity::query()
                ->whereKey($activity->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedActivity || $this->reminderAlreadySent($lockedActivity, $key)) {
                return false;
            }

            $settings = $lockedActivity->settings ?? [];
            $settings[$key] = $timestamp->toIso8601String();

            $lockedActivity->forceFill([
                'settings' => $settings,
            ])->save();

            $activity->forceFill([
                'settings' => $settings,
            ]);

            return true;
        });
    }
}
