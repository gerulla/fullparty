<?php

namespace App\Http\Middleware;

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\UserOnboardingState;
use App\Services\Notifications\NotificationInboxService;
use App\Services\Notifications\NotificationPreferenceSettingsService;
use App\Services\SystemBannerService;
use App\Support\Groups\GroupDiscoveryBadgePalette;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly NotificationInboxService $notificationInboxService,
        private readonly NotificationPreferenceSettingsService $notificationPreferenceSettingsService,
        private readonly SystemBannerService $systemBannerService,
        private readonly GroupDiscoveryBadgePalette $groupDiscoveryBadgePalette,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function rootView(Request $request): string
    {
        return $request->routeIs('planner.*') ? 'planner' : parent::rootView($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        if ($request->routeIs('planner.*')) {
            return array_merge(parent::share($request), [
                'auth' => [
                    'user' => fn () => $request->user()
                        ? $this->serializePlannerUser($request->user())
                        : null,
                ],
                'locale' => [
                    'current' => fn () => app()->getLocale(),
                    'fallback' => fn () => config('app.fallback_locale'),
                    'available' => fn () => ApplyLocale::SUPPORTED_LOCALES,
                ],
                'planner' => [
                    'csrf_token' => fn () => csrf_token(),
                    'routes' => fn () => collect([
                        'dashboard',
                        'settings',
                        'logout',
                        'login',
                        'register',
                    ])->mapWithKeys(fn (string $routeName) => [
                        $routeName => rtrim((string) config('app.url'), '/').route($routeName, absolute: false),
                    ])->all(),
                ],
            ]);
        }

        return array_merge(parent::share($request), [
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'data' => fn () => $request->session()->get('flash_data', []), ],
            'auth' => [
                'user' => fn () => $request->user()
                    ? $this->serializeAuthenticatedUser($request->user())
                    : null,
            ],
            'navigation' => [
                'group_quick_links' => fn () => $request->user()
                    ? [
                        'my' => $this->serializeGroupQuickLinks(
                            $request->user()->groups()
                                ->wherePivotIn('role', [
                                    GroupMembership::ROLE_OWNER,
                                    GroupMembership::ROLE_ADMIN,
                                    GroupMembership::ROLE_MODERATOR,
                                ])
                                ->get(['groups.id', 'groups.name', 'groups.slug'])
                        ),
                        'joined' => $this->serializeGroupQuickLinks(
                            $request->user()->memberGroups()->get(['groups.id', 'groups.name', 'groups.slug'])
                        ),
                    ]
                    : [
                        'my' => [],
                        'joined' => [],
                    ],
            ],
            'notifications' => [
                'unread_count' => fn () => $request->user()
                    ? $this->notificationInboxService->unreadCount($request->user())
                    : 0,
                'latest' => fn () => $request->user()
                    ? $this->notificationInboxService->latest($request->user(), 5)
                    : [],
            ],
            'system_banner' => fn () => $this->systemBannerService->serialize(),
            'app_version' => fn () => $this->serializeAppVersion(),
            'site_links' => [
                'discord' => fn () => config('services.project_links.discord'),
                'github' => fn () => config('services.project_links.github'),
            ],
            'legal' => [
                'controller_name' => fn () => config('services.legal.controller_name'),
                'contact_email' => fn () => config('services.legal.contact_email'),
            ],
            'onboarding' => fn () => $request->user()
                ? $this->serializeOnboardingState($request->user())
                : null,
            'lookups' => [
                'datacenters' => fn () => collect(config('datacenters.values', []))
                    ->map(fn (string $value) => [
                        'label' => $value,
                        'value' => $value,
                        'region' => Group::regionForDatacenter($value),
                    ])
                    ->values()
                    ->all(),
                'group_discovery' => fn () => [
                    'primary_focuses' => config('group_discovery.primary_focuses', []),
                    'experience_expectations' => config('group_discovery.experience_expectations', []),
                    'voice_expectations' => config('group_discovery.voice_expectations', []),
                    'active_days' => config('group_discovery.active_days', []),
                    'preferred_languages' => config('group_discovery.preferred_languages', []),
                    'max_tags' => config('group_discovery.max_tags', 12),
                    'badge_colors' => $this->groupDiscoveryBadgePalette->lookupColors(),
                ],
            ],
            'locale' => [
                'current' => fn () => app()->getLocale(),
                'fallback' => fn () => config('app.fallback_locale'),
                'available' => fn () => ApplyLocale::SUPPORTED_LOCALES,
            ],
        ]);
    }

    /**
     * @param  Collection<int, Group>  $groups
     * @return array<int, array<string, string|int>>
     */
    private function serializeGroupQuickLinks($groups): array
    {
        return $groups
            ->sortBy('name')
            ->values()
            ->map(fn (Group $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'href' => route('groups.dashboard', $group, false),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePlannerUser(User $user): array
    {
        $user->loadMissing('primaryCharacter');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar_url' => $user->avatar_url,
            'primary_character' => $user->primaryCharacter ? [
                'id' => $user->primaryCharacter->id,
                'name' => $user->primaryCharacter->name,
                'avatar_url' => $user->primaryCharacter->avatar_url,
            ] : null,
        ];
    }

    /**
     * @return array{version: string, commit: string|null, deployed_at: string|null}
     */
    private function serializeAppVersion(): array
    {
        $path = storage_path('app/version.json');

        if (! is_file($path)) {
            return [
                'version' => 'dev',
                'commit' => null,
                'deployed_at' => null,
            ];
        }

        $payload = json_decode((string) file_get_contents($path), true);

        if (! is_array($payload)) {
            return [
                'version' => 'dev',
                'commit' => null,
                'deployed_at' => null,
            ];
        }

        return [
            'version' => filled($payload['version'] ?? null) ? (string) $payload['version'] : 'dev',
            'commit' => filled($payload['commit'] ?? null) ? (string) $payload['commit'] : null,
            'deployed_at' => filled($payload['deployed_at'] ?? null) ? (string) $payload['deployed_at'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAuthenticatedUser(User $user): array
    {
        $user->loadMissing(['primaryCharacter', 'discordUserIntegration']);
        $socialAccounts = $user->socialAccounts()
            ->safeSummary()
            ->get();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'is_admin' => (bool) $user->is_admin,
            'public_profile' => (bool) $user->public_profile,
            'public_characters' => (bool) $user->public_characters,
            'application_notifications' => (bool) $user->application_notifications,
            'run_and_reminder_notifications' => (bool) $user->run_and_reminder_notifications,
            'group_update_notifications' => (bool) $user->group_update_notifications,
            'assignment_notifications' => (bool) $user->assignment_notifications,
            'account_character_notifications' => (bool) $user->account_character_notifications,
            'system_notice_notifications' => (bool) $user->system_notice_notifications,
            'email_notifications' => (bool) $user->email_notifications,
            'discord_notifications' => (bool) $user->discord_notifications,
            'notification_preferences' => $this->notificationPreferenceSettingsService->serializeUserPreferences($user),
            'time_display_mode' => $user->time_display_mode ?: User::TIME_DISPLAY_LOCAL,
            'homepage_group_id' => $user->homepage_group_id,
            'discord_link_token_expires_at' => $user->discord_link_token_expires_at?->toIso8601String(),
            'discord_user_integration' => $user->discordUserIntegration ? [
                'id' => $user->discordUserIntegration->id,
                'discord_user_id' => $user->discordUserIntegration->discord_user_id,
                'username' => $user->discordUserIntegration->username,
                'global_name' => $user->discordUserIntegration->global_name,
                'avatar_url' => $user->discordUserIntegration->avatar_url,
                'user_app_installed_at' => $user->discordUserIntegration->user_app_installed_at?->toIso8601String(),
            ] : null,
            'notification_preferences_reviewed_at' => $user->notification_preferences_reviewed_at?->toIso8601String(),
            'primary_character' => $user->primaryCharacter ? [
                'id' => $user->primaryCharacter->id,
                'name' => $user->primaryCharacter->name,
                'world' => $user->primaryCharacter->world,
                'datacenter' => $user->primaryCharacter->datacenter,
                'avatar_url' => $user->primaryCharacter->avatar_url,
            ] : null,
            'social_accounts' => $socialAccounts
                ->map(fn (SocialAccount $socialAccount) => [
                    'id' => $socialAccount->id,
                    'provider' => $socialAccount->provider,
                    'provider_name' => $socialAccount->provider_name,
                    'provider_email' => $socialAccount->provider_email,
                    'avatar_url' => $socialAccount->avatar_url,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOnboardingState(User $user): array
    {
        $state = $user->onboardingState()->firstOrCreate([
            'user_id' => $user->id,
        ], [
            'current_step' => UserOnboardingState::STEP_WELCOME,
        ]);

        return $state->toSharedPayload();
    }
}
