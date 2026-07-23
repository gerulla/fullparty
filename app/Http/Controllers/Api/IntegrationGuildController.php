<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\DiscordGuildIntegration;
use App\Models\Group;
use App\Models\User;
use App\Services\Groups\ActivitySlotKind;
use App\Support\Activities\ActivityDisplayName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class IntegrationGuildController extends Controller
{
    private const PAYLOAD_INVALID_ERROR = 'discord_guild_link_payload_invalid';

    public function __construct(
        private readonly ActivitySlotKind $slotKind,
    ) {}

    public function link(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'discord_guild_id' => ['required', 'string', 'max:64', 'regex:/^\d{1,32}$/'],
            'token' => ['required', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:120'],
            'icon_url' => ['nullable', 'url:http,https', 'max:2048'],
            'permissions' => ['nullable', 'string', 'max:64'],
        ], $this->payloadValidationMessages());

        $group = Group::query()
            ->whereIn('discord_link_token_hash', $this->linkTokenHashes($validated['token']))
            ->where('discord_link_token_expires_at', '>', now())
            ->first();

        if (! $group) {
            throw ValidationException::withMessages([
                'token' => 'discord_guild_link_token_invalid',
            ]);
        }

        $existingGuildIntegration = DiscordGuildIntegration::query()
            ->where('discord_guild_id', $validated['discord_guild_id'])
            ->whereNull('removed_at')
            ->first();

        if ($existingGuildIntegration
            && $existingGuildIntegration->group_id !== null
            && (int) $existingGuildIntegration->group_id !== (int) $group->id) {
            throw ValidationException::withMessages([
                'discord_guild_id' => 'discord_guild_already_linked',
            ]);
        }

        DiscordGuildIntegration::query()
            ->where('group_id', $group->id)
            ->whereNull('removed_at')
            ->where('discord_guild_id', '!=', $validated['discord_guild_id'])
            ->update(['group_id' => null]);

        $integration = DiscordGuildIntegration::query()->updateOrCreate([
            'discord_guild_id' => $validated['discord_guild_id'],
        ], [
            'group_id' => $group->id,
            'name' => $validated['name'] ?? $existingGuildIntegration?->name,
            'icon_url' => $validated['icon_url'] ?? $existingGuildIntegration?->icon_url,
            'permissions' => $validated['permissions'] ?? $existingGuildIntegration?->permissions,
            'guild_installed_at' => $existingGuildIntegration?->guild_installed_at ?? now(),
            'removed_at' => null,
        ]);

        $group->forceFill([
            'discord_link_token_hash' => null,
            'discord_link_token_expires_at' => null,
        ])->save();

        return response()->json([
            'data' => [
                'linked' => true,
                'group' => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'slug' => $group->slug,
                ],
                'guild' => [
                    'id' => $integration->id,
                    'discord_guild_id' => $integration->discord_guild_id,
                    'name' => $integration->name,
                    'icon_url' => $integration->icon_url,
                    'guild_installed_at' => $integration->guild_installed_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    public function upcomingRuns(string $discordGuildId, Request $request): JsonResponse
    {
        $guildIntegration = $this->activeGuildIntegration($discordGuildId);
        $limit = max(1, min(100, (int) $request->integer('limit', 25)));

        $activities = Activity::query()
            ->with([
                'group:id,name,slug',
                'activityTypeVersion:id,name,small_image_url,banner_image_url,difficulty,prog_points',
                'organizer:id,name,avatar_url',
                'organizer.discordUserIntegration:id,user_id,discord_user_id,user_app_installed_at',
                'organizerCharacter:id,name,world,datacenter,avatar_url',
            ])
            ->withCount([
                'slots as assigned_slot_count' => fn ($query) => $query
                    ->where('group_key', '!=', 'bench')
                    ->where('slot_kind', '!=', ActivitySlot::SLOT_KIND_FILL_IN)
                    ->whereNotNull('assigned_character_id'),
                'slots as total_slot_count' => fn ($query) => $query
                    ->where('group_key', '!=', 'bench')
                    ->where('slot_kind', '!=', ActivitySlot::SLOT_KIND_FILL_IN),
                'applications as total_applicant_count' => fn ($query) => $query
                    ->whereIn('status', ActivityApplication::ACTIVE_STATUSES),
            ])
            ->where('group_id', $guildIntegration->group_id)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', now())
            ->whereNotIn('status', [
                Activity::STATUS_DRAFT,
                Activity::STATUS_COMPLETE,
                Activity::STATUS_CANCELLED,
            ])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $activities
                ->map(fn (Activity $activity): array => $this->serializeGuildRun($activity))
                ->values(),
            'meta' => [
                'discord_guild_id' => $guildIntegration->discord_guild_id,
                'group' => $this->serializeGroup($guildIntegration->group),
                'count' => $activities->count(),
                'limit' => $limit,
            ],
        ]);
    }

    public function roleAssignment(string $discordGuildId, Activity $activity): JsonResponse
    {
        $guildIntegration = $this->activeGuildIntegration($discordGuildId);

        abort_unless((int) $activity->group_id === (int) $guildIntegration->group_id, 404);

        $activity->loadMissing([
            'group:id,name,slug,owner_id',
            'group.memberships:id,group_id,user_id,role',
            'activityTypeVersion:id,name,small_image_url,banner_image_url,difficulty,prog_points',
            'organizer:id,name,avatar_url',
            'organizer.discordUserIntegration:id,user_id,discord_user_id,user_app_installed_at',
            'organizerCharacter:id,name,world,datacenter,avatar_url',
            'slots.assignedCharacter.user.discordUserIntegration',
            'slots.assignedCharacter.user.primaryCharacter',
            'applications.user.discordUserIntegration',
            'applications.user.primaryCharacter',
            'applications.selectedCharacter:id,user_id,name,world,datacenter,avatar_url',
        ]);

        $placedUsers = $this->placedRunUsers($activity);
        $group = $activity->group;
        $participants = $placedUsers
            ->map(fn (array $entry): ?array => $this->serializeRoleAssignmentParticipant($entry, $group))
            ->filter()
            ->unique('discord_user_id')
            ->values();
        $unlinkedParticipants = $placedUsers
            ->filter(fn (array $entry): bool => $this->activeDiscordUserId($entry['user'] ?? null) === null)
            ->map(fn (array $entry): ?array => $this->serializeRoleAssignmentParticipant($entry, $group, false))
            ->filter()
            ->values();

        return response()->json([
            'data' => [
                'run' => $this->serializeGuildRun($activity),
                'group' => $this->serializeGroup($group),
                'discord_guild' => [
                    'id' => $guildIntegration->discord_guild_id,
                    'name' => $guildIntegration->name,
                    'icon_url' => $guildIntegration->icon_url,
                ],
                'discord_user_ids' => $participants
                    ->pluck('discord_user_id')
                    ->filter()
                    ->values()
                    ->all(),
                'participants' => $participants->all(),
                'unlinked_participants' => $unlinkedParticipants->all(),
                'unlinked_count' => $unlinkedParticipants->count(),
                'total_placed_count' => $placedUsers->count(),
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function payloadValidationMessages(): array
    {
        return [
            'discord_guild_id.required' => self::PAYLOAD_INVALID_ERROR,
            'discord_guild_id.string' => self::PAYLOAD_INVALID_ERROR,
            'discord_guild_id.max' => self::PAYLOAD_INVALID_ERROR,
            'discord_guild_id.regex' => self::PAYLOAD_INVALID_ERROR,
            'token.required' => self::PAYLOAD_INVALID_ERROR,
            'token.string' => self::PAYLOAD_INVALID_ERROR,
            'token.max' => self::PAYLOAD_INVALID_ERROR,
            'name.string' => self::PAYLOAD_INVALID_ERROR,
            'name.max' => self::PAYLOAD_INVALID_ERROR,
            'icon_url.url' => self::PAYLOAD_INVALID_ERROR,
            'icon_url.max' => self::PAYLOAD_INVALID_ERROR,
            'permissions.string' => self::PAYLOAD_INVALID_ERROR,
            'permissions.max' => self::PAYLOAD_INVALID_ERROR,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function linkTokenHashes(string $token): array
    {
        $normalizedToken = strtoupper(preg_replace('/\s+/', '', trim($token)) ?? '');
        $compactToken = preg_replace('/[^A-Z0-9]+/', '', $normalizedToken) ?? '';
        $formattedCompactToken = strlen($compactToken) === 16
            ? substr($compactToken, 0, 8).'-'.substr($compactToken, 8)
            : $compactToken;

        return collect([
            $normalizedToken,
            $compactToken,
            $formattedCompactToken,
        ])
            ->filter()
            ->unique()
            ->map(fn (string $candidate): string => hash('sha256', $candidate))
            ->values()
            ->all();
    }

    private function activeGuildIntegration(string $discordGuildId): DiscordGuildIntegration
    {
        $integration = DiscordGuildIntegration::query()
            ->with('group:id,name,slug')
            ->where('discord_guild_id', $discordGuildId)
            ->whereNull('removed_at')
            ->whereNotNull('group_id')
            ->first();

        if (! $integration?->group) {
            abort(404, 'Discord guild is not linked to a FullParty group.');
        }

        return $integration;
    }

    /**
     * @return array{id: int|null, name: string|null, slug: string|null}
     */
    private function serializeGroup(?Group $group): array
    {
        return [
            'id' => $group?->id,
            'name' => $group?->name,
            'slug' => $group?->slug,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeGuildRun(Activity $activity): array
    {
        $activity->loadMissing([
            'group',
            'activityTypeVersion',
            'organizer.discordUserIntegration',
            'organizerCharacter',
        ]);
        $counts = $this->serializeRunCounts($activity);
        $host = $this->serializeRunHost($activity);

        return [
            'id' => $activity->id,
            'status' => $activity->status,
            'title' => $activity->title,
            'display_name' => ActivityDisplayName::for($activity),
            'starts_at' => $activity->starts_at?->toIso8601String(),
            'duration_hours' => $activity->duration_hours,
            'datacenter' => $activity->datacenter,
            'run_style' => $activity->run_style,
            'intensity' => $activity->intensity,
            'target_prog_point_key' => $activity->target_prog_point_key,
            'target_prog_point' => $this->serializeTargetProgPoint($activity),
            'is_public' => (bool) $activity->is_public,
            'needs_application' => (bool) $activity->needs_application,
            'counts' => $counts,
            'group' => $this->serializeGroup($activity->group),
            'activity_type' => [
                'id' => $activity->activityTypeVersion?->id,
                'name' => $activity->activityTypeVersion?->name,
                'difficulty' => $activity->activityTypeVersion?->difficulty,
                'small_image_url' => $activity->activityTypeVersion?->small_image_url,
                'banner_image_url' => $activity->activityTypeVersion?->banner_image_url,
            ],
            'organizer' => [
                'id' => $activity->organizer?->id,
                'name' => $activity->organizer?->name,
                'avatar_url' => $activity->organizer?->avatar_url,
                'discord_user_id' => $this->activeDiscordUserId($activity->organizer),
                'character' => $activity->organizerCharacter ? [
                    'id' => $activity->organizerCharacter->id,
                    'name' => $activity->organizerCharacter->name,
                    'world' => $activity->organizerCharacter->world,
                    'datacenter' => $activity->organizerCharacter->datacenter,
                    'avatar_url' => $activity->organizerCharacter->avatar_url,
                ] : null,
            ],
            'host' => $host,
            'urls' => [
                'overview' => route('groups.activities.overview', [
                    'group' => $activity->group,
                    'activity' => $activity,
                ], false),
                'application' => route('groups.activities.application', [
                    'group' => $activity->group,
                    'activity' => $activity,
                ], false),
            ],
        ];
    }

    /**
     * @return array{user_id: int|null, name: string|null, avatar_url: string|null, discord_user_id: string|null, character: array{id: int, name: string, world: string, datacenter: string, avatar_url: string|null}|null}
     */
    private function serializeRunHost(Activity $activity): array
    {
        return [
            'user_id' => $activity->organizer?->id,
            'name' => $activity->organizer?->name,
            'avatar_url' => $activity->organizer?->avatar_url,
            'discord_user_id' => $this->activeDiscordUserId($activity->organizer),
            'character' => $activity->organizerCharacter ? [
                'id' => $activity->organizerCharacter->id,
                'name' => $activity->organizerCharacter->name,
                'world' => $activity->organizerCharacter->world,
                'datacenter' => $activity->organizerCharacter->datacenter,
                'avatar_url' => $activity->organizerCharacter->avatar_url,
            ] : null,
        ];
    }

    /**
     * @return array{key: string, label: mixed, order: int|null}|null
     */
    private function serializeTargetProgPoint(Activity $activity): ?array
    {
        if (blank($activity->target_prog_point_key)) {
            return null;
        }

        $key = (string) $activity->target_prog_point_key;
        $progPoint = collect($activity->activityTypeVersion?->prog_points ?? [])
            ->filter(fn (mixed $point): bool => is_array($point))
            ->first(fn (array $point): bool => ($point['key'] ?? null) === $key);

        if (! is_array($progPoint)) {
            return [
                'key' => $key,
                'label' => null,
                'order' => null,
            ];
        }

        return [
            'key' => $key,
            'label' => $progPoint['label'] ?? null,
            'order' => isset($progPoint['order']) ? (int) $progPoint['order'] : null,
        ];
    }

    /**
     * @return array{assigned_slots: int, total_slots: int, total_applicants: int}
     */
    private function serializeRunCounts(Activity $activity): array
    {
        if ($activity->getAttribute('assigned_slot_count') !== null
            && $activity->getAttribute('total_slot_count') !== null
            && $activity->getAttribute('total_applicant_count') !== null) {
            return [
                'assigned_slots' => (int) $activity->getAttribute('assigned_slot_count'),
                'total_slots' => (int) $activity->getAttribute('total_slot_count'),
                'total_applicants' => (int) $activity->getAttribute('total_applicant_count'),
            ];
        }

        $activity->loadMissing([
            'slots:id,activity_id,slot_kind,group_key,assigned_character_id',
            'applications:id,activity_id,status',
        ]);

        $mainSlots = $activity->slots
            ->filter(fn (ActivitySlot $slot): bool => $this->slotKind->isMainRoster($slot));

        return [
            'assigned_slots' => $mainSlots
                ->filter(fn (ActivitySlot $slot): bool => $slot->assigned_character_id !== null)
                ->count(),
            'total_slots' => $mainSlots->count(),
            'total_applicants' => $activity->applications
                ->filter(fn (ActivityApplication $application): bool => in_array(
                    $application->status,
                    ActivityApplication::ACTIVE_STATUSES,
                    true
                ))
                ->count(),
        ];
    }

    /**
     * @return Collection<int, array{user: User|null, character: mixed, source: string, slot: ActivitySlot|null, application: ActivityApplication|null}>
     */
    private function placedRunUsers(Activity $activity): Collection
    {
        $entries = collect();

        $activity->slots
            ->filter(fn (ActivitySlot $slot): bool => $slot->assignedCharacter?->user instanceof User)
            ->each(function (ActivitySlot $slot) use ($entries): void {
                $entries->push([
                    'user' => $slot->assignedCharacter?->user,
                    'character' => $slot->assignedCharacter,
                    'source' => 'slot',
                    'slot' => $slot,
                    'application' => null,
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
                    'slot' => null,
                    'application' => $application,
                ]);
            });

        return $entries
            ->sortBy(fn (array $entry): int => $entry['source'] === 'slot' ? 0 : 1)
            ->unique(fn (array $entry): ?int => $entry['user']?->id)
            ->values();
    }

    /**
     * @param  array{user: User|null, character: mixed, source: string, slot: ActivitySlot|null, application: ActivityApplication|null}  $entry
     * @return array<string, mixed>|null
     */
    private function serializeRoleAssignmentParticipant(array $entry, Group $group, bool $requireDiscordUser = true): ?array
    {
        $user = $entry['user'];
        $discordUserId = $this->activeDiscordUserId($user);

        if (! $user || ($requireDiscordUser && $discordUserId === null)) {
            return null;
        }

        $character = $entry['character'] ?: $user->primaryCharacter;
        $slot = $entry['slot'];
        $application = $entry['application'];
        $groupRole = $this->groupRoleForUser($group, $user);

        return [
            'user_id' => $user->id,
            'discord_user_id' => $discordUserId,
            'is_discord_linked' => $discordUserId !== null,
            'is_group_member' => $groupRole !== null,
            'group_role' => $groupRole,
            'source' => $entry['source'],
            'character' => $character ? [
                'id' => $character->id,
                'name' => $character->name,
                'world' => $character->world,
                'datacenter' => $character->datacenter,
                'avatar_url' => $character->avatar_url ?? null,
            ] : null,
            'slot' => $slot ? [
                'id' => $slot->id,
                'group_key' => $slot->group_key,
                'group_label' => $slot->group_label,
                // TODO (2026-07-18): Expose filled party metadata for fill-in slots.
                'slot_key' => $slot->slot_key,
                'slot_label' => $slot->slot_label,
                'is_bench' => $slot->group_key === 'bench',
                'is_fill_in' => $this->slotKind->isFillIn($slot),
                'is_host' => (bool) $slot->is_host,
                'is_raid_leader' => (bool) $slot->is_raid_leader,
                'attendance_status' => $slot->attendance_status ?? null,
            ] : null,
            'application' => $application ? [
                'id' => $application->id,
                'status' => $application->status,
            ] : null,
        ];
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

    private function activeDiscordUserId(?User $user): ?string
    {
        $integration = $user?->discordUserIntegration;

        if (! $integration || blank($integration->discord_user_id) || $integration->user_app_installed_at === null) {
            return null;
        }

        return $integration->discord_user_id;
    }
}
