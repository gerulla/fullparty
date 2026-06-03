<?php

namespace App\Http\Controllers\Api;

use App\Events\DiscordUserAppInstalled;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\DiscordUserIntegration;
use App\Models\User;
use App\Models\UserOnboardingState;
use App\Services\AuditLogger;
use App\Support\Activities\ActivityDisplayName;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class IntegrationUserController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function primaryCharacters(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'discord_user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'discord_user_ids.*' => ['required', 'string', 'distinct', 'regex:/^\d{1,32}$/'],
        ]);

        $discordUserIds = collect($validated['discord_user_ids'])
            ->map(fn (string $discordUserId) => trim($discordUserId))
            ->values();

        $integrationsByDiscordId = DiscordUserIntegration::query()
            ->with('user.primaryCharacter:id,user_id,name,world,datacenter')
            ->whereIn('discord_user_id', $discordUserIds)
            ->whereNull('revoked_at')
            ->get()
            ->keyBy('discord_user_id');

        return response()->json([
            'data' => $discordUserIds
                ->map(function (string $discordUserId) use ($integrationsByDiscordId): array {
                    /** @var DiscordUserIntegration|null $integration */
                    $integration = $integrationsByDiscordId->get($discordUserId);
                    $primaryCharacter = $integration?->user?->primaryCharacter;

                    return [
                        'discord_user_id' => $discordUserId,
                        'linked' => $integration !== null,
                        'user_id' => $integration?->user_id,
                        'primary_character' => $primaryCharacter ? [
                            'id' => $primaryCharacter->id,
                            'name' => $primaryCharacter->name,
                            'world' => $primaryCharacter->world,
                            'datacenter' => $primaryCharacter->datacenter,
                        ] : null,
                    ];
                })
                ->values(),
        ]);
    }

    public function link(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'discord_user_id' => ['required', 'string', 'max:64', 'regex:/^\d{1,32}$/'],
            'token' => ['required', 'string', 'max:64'],
            'username' => ['nullable', 'string', 'max:120'],
            'global_name' => ['nullable', 'string', 'max:120'],
            'avatar_url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);

        $user = User::query()
            ->where('discord_link_token_hash', hash('sha256', $validated['token']))
            ->where('discord_link_token_expires_at', '>', now())
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'token' => 'discord_user_link_token_invalid',
            ]);
        }

        $conflictingIntegration = DiscordUserIntegration::query()
            ->where('discord_user_id', $validated['discord_user_id'])
            ->where('user_id', '!=', $user->id)
            ->first();

        if ($conflictingIntegration) {
            throw ValidationException::withMessages([
                'discord_user_id' => 'discord_user_already_linked',
            ]);
        }

        $integration = DiscordUserIntegration::query()->updateOrCreate([
            'user_id' => $user->id,
        ], [
            'discord_user_id' => $validated['discord_user_id'],
            'username' => $validated['username'] ?? null,
            'global_name' => $validated['global_name'] ?? null,
            'avatar_url' => $validated['avatar_url'] ?? null,
            'user_app_installed_at' => now(),
            'revoked_at' => null,
        ]);

        $user->forceFill([
            'discord_link_token_hash' => null,
            'discord_link_token_expires_at' => null,
        ])->save();

        DiscordUserAppInstalled::dispatch($integration->id);

        $this->auditLogger->log(
            action: 'user.discord_app.user_installed',
            severity: AuditSeverity::INFO,
            scopeType: AuditScope::USER,
            scopeId: $user->id,
            message: 'audit_log.activity.user.discord_app.user_installed',
            actor: $user,
            subject: $user,
            metadata: [
                'discord_user_id' => $validated['discord_user_id'],
                'linked_via' => 'bot_token',
            ],
        );

        $user->onboardingState()->firstOrCreate([
            'user_id' => $user->id,
        ], [
            'current_step' => UserOnboardingState::STEP_WELCOME,
        ])->update([
            'current_step' => UserOnboardingState::STEP_NOTIFICATIONS,
        ]);

        return response()->json([
            'data' => [
                'linked' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                ],
                'discord_user' => [
                    'id' => $integration->discord_user_id,
                    'username' => $integration->username,
                    'global_name' => $integration->global_name,
                    'avatar_url' => $integration->avatar_url,
                ],
            ],
        ]);
    }

    public function upcomingRuns(string $discordUserId): JsonResponse
    {
        $user = $this->userForDiscordId($discordUserId);
        $characterIds = $user->characters()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $activities = Activity::query()
            ->with([
                'group:id,name,slug',
                'activityTypeVersion:id,name,small_image_url,banner_image_url,difficulty',
                'organizer:id,name',
                'organizerCharacter:id,name,world,datacenter',
                'slots.assignedCharacter:id,user_id,name,world,datacenter',
                'applications' => fn ($query) => $query
                    ->where('user_id', $user->id)
                    ->whereIn('status', [
                        ActivityApplication::STATUS_APPROVED,
                        ActivityApplication::STATUS_ON_BENCH,
                    ])
                    ->with('selectedCharacter:id,name,world,datacenter'),
            ])
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', now())
            ->whereNotIn('status', [
                Activity::STATUS_DRAFT,
                Activity::STATUS_COMPLETE,
                Activity::STATUS_CANCELLED,
            ])
            ->where(function ($query) use ($user, $characterIds): void {
                $query->where('organized_by_user_id', $user->id)
                    ->orWhereHas('applications', fn ($applicationQuery) => $applicationQuery
                        ->where('user_id', $user->id)
                        ->whereIn('status', [
                            ActivityApplication::STATUS_APPROVED,
                            ActivityApplication::STATUS_ON_BENCH,
                        ]));

                if ($characterIds !== []) {
                    $query
                        ->orWhereHas('slots', fn ($slotQuery) => $slotQuery->whereIn('assigned_character_id', $characterIds))
                        ->orWhereHas('slotAssignments', fn ($assignmentQuery) => $assignmentQuery
                            ->whereIn('character_id', $characterIds)
                            ->whereNull('ended_at')
                            ->whereIn('attendance_status', [
                                ActivitySlotAssignment::STATUS_ASSIGNED,
                                ActivitySlotAssignment::STATUS_CHECKED_IN,
                                ActivitySlotAssignment::STATUS_LATE,
                            ]));
                }
            })
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(6)
            ->get();

        return response()->json([
            'data' => $activities
                ->map(fn (Activity $activity) => $this->serializeRun($activity, $user, $characterIds))
                ->values(),
        ]);
    }

    public function applications(string $discordUserId): JsonResponse
    {
        $user = $this->userForDiscordId($discordUserId);

        $applications = ActivityApplication::query()
            ->with([
                'activity.group:id,name,slug',
                'activity.activityTypeVersion:id,name,small_image_url,banner_image_url,difficulty',
                'selectedCharacter:id,name,world,datacenter,avatar_url',
            ])
            ->where('user_id', $user->id)
            ->whereIn('status', ActivityApplication::ACTIVE_STATUSES)
            ->whereHas('activity', fn ($query) => $query
                ->whereNotIn('status', Activity::ARCHIVED_STATUSES))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $applications
                ->map(fn (ActivityApplication $application) => $this->serializeApplication($application))
                ->values(),
        ]);
    }

    private function userForDiscordId(string $discordUserId): User
    {
        $user = User::query()
            ->whereHas('discordUserIntegration', fn ($query) => $query
                ->where('discord_user_id', $discordUserId)
                ->whereNull('revoked_at'))
            ->first();

        if (! $user) {
            abort(404, 'Discord user is not linked to a FullParty account.');
        }

        return $user;
    }

    /**
     * @param  array<int, int>  $characterIds
     * @return array<string, mixed>
     */
    private function serializeRun(Activity $activity, User $user, array $characterIds): array
    {
        $assignedSlot = $activity->slots
            ->first(fn ($slot) => in_array((int) $slot->assigned_character_id, $characterIds, true));
        $application = $activity->applications->firstWhere('user_id', $user->id);

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
            'group' => [
                'id' => $activity->group?->id,
                'name' => $activity->group?->name,
                'slug' => $activity->group?->slug,
            ],
            'activity_type' => [
                'name' => $activity->activityTypeVersion?->name,
                'difficulty' => $activity->activityTypeVersion?->difficulty,
                'small_image_url' => $activity->activityTypeVersion?->small_image_url,
                'banner_image_url' => $activity->activityTypeVersion?->banner_image_url,
            ],
            'organizer' => [
                'id' => $activity->organizer?->id,
                'name' => $activity->organizer?->name,
                'character' => $activity->organizerCharacter ? [
                    'id' => $activity->organizerCharacter->id,
                    'name' => $activity->organizerCharacter->name,
                    'world' => $activity->organizerCharacter->world,
                    'datacenter' => $activity->organizerCharacter->datacenter,
                ] : null,
            ],
            'user_context' => [
                'is_host' => (int) $activity->organized_by_user_id === (int) $user->id,
                'is_assigned' => $assignedSlot !== null,
                'application_status' => $application?->status,
                'slot' => $assignedSlot ? [
                    'id' => $assignedSlot->id,
                    'group_key' => $assignedSlot->group_key,
                    'group_label' => $assignedSlot->group_label,
                    'slot_key' => $assignedSlot->slot_key,
                    'slot_label' => $assignedSlot->slot_label,
                    'character' => $assignedSlot->assignedCharacter ? [
                        'id' => $assignedSlot->assignedCharacter->id,
                        'name' => $assignedSlot->assignedCharacter->name,
                        'world' => $assignedSlot->assignedCharacter->world,
                        'datacenter' => $assignedSlot->assignedCharacter->datacenter,
                    ] : null,
                ] : null,
            ],
            'urls' => [
                'overview' => route('groups.activities.overview', [
                    'group' => $activity->group,
                    'activity' => $activity,
                ], false),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeApplication(ActivityApplication $application): array
    {
        $activity = $application->activity;
        $character = $application->selectedCharacter;

        return [
            'id' => $application->id,
            'status' => $application->status,
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'reviewed_at' => $application->reviewed_at?->toIso8601String(),
            'review_reason' => $application->review_reason,
            'notes' => $application->notes,
            'character' => [
                'id' => $character?->id,
                'name' => $character?->name ?? $application->applicant_character_name,
                'world' => $character?->world ?? $application->applicant_world,
                'datacenter' => $character?->datacenter ?? $application->applicant_datacenter,
                'avatar_url' => $character?->avatar_url ?? $application->applicant_avatar_url,
            ],
            'activity' => [
                'id' => $activity?->id,
                'status' => $activity?->status,
                'title' => $activity?->title,
                'display_name' => ActivityDisplayName::for($activity),
                'starts_at' => $activity?->starts_at?->toIso8601String(),
                'duration_hours' => $activity?->duration_hours,
                'datacenter' => $activity?->datacenter,
                'run_style' => $activity?->run_style,
                'intensity' => $activity?->intensity,
                'group' => [
                    'id' => $activity?->group?->id,
                    'name' => $activity?->group?->name,
                    'slug' => $activity?->group?->slug,
                ],
                'activity_type' => [
                    'name' => $activity?->activityTypeVersion?->name,
                    'difficulty' => $activity?->activityTypeVersion?->difficulty,
                    'small_image_url' => $activity?->activityTypeVersion?->small_image_url,
                    'banner_image_url' => $activity?->activityTypeVersion?->banner_image_url,
                ],
            ],
            'urls' => [
                'overview' => $activity ? route('groups.activities.overview', [
                    'group' => $activity->group,
                    'activity' => $activity,
                ], false) : null,
            ],
        ];
    }
}
