<?php

namespace App\Http\Controllers;

use App\Models\CharacterClass;
use App\Models\User;
use App\Models\UserHomeProfile;
use App\Services\Dashboard\HomeAccountCompletionDataService;
use App\Services\Dashboard\HomeActivityOverviewDataService;
use App\Services\Dashboard\HomeBannerDataService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserProfileController extends Controller
{
    public function __construct(
        private readonly HomeBannerDataService $homeBannerDataService,
        private readonly HomeActivityOverviewDataService $homeActivityOverviewDataService,
        private readonly HomeAccountCompletionDataService $homeAccountCompletionDataService,
    ) {}

    public function show(Request $request, User $user): Response
    {
        abort_unless($request->user()->is($user), 404);

        return Inertia::render('Profile/Show', [
            'profile' => fn () => $this->serializeProfile($user),
            'homeProfileOptions' => fn () => [
                'character_classes' => $this->serializeCharacterClassOptions(),
            ],
            'homeBanner' => fn () => $this->homeBannerDataService->baseForUser($user),
            'homeBannerDetails' => Inertia::defer(
                fn () => $this->homeBannerDataService->detailsForUser($request->user()),
                'home-banner-details',
            ),
            'homeActivityOverview' => Inertia::defer(
                fn () => $this->homeActivityOverviewDataService->forUser($request->user()),
                'home-activity-overview',
            ),
            'homeAccountCompletion' => Inertia::defer(
                fn () => $this->homeAccountCompletionDataService->forUser($request->user()),
                'home-account-completion',
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProfile(User $user): array
    {
        $user->loadMissing([
            'homeProfile.displayCharacterClass',
            'primaryCharacter',
        ]);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'home_profile' => $this->serializeHomeProfile($user->homeProfile),
            'primary_character' => $user->primaryCharacter ? [
                'id' => $user->primaryCharacter->id,
                'name' => $user->primaryCharacter->name,
                'world' => $user->primaryCharacter->world,
                'datacenter' => $user->primaryCharacter->datacenter,
                'avatar_url' => $user->primaryCharacter->avatar_url,
            ] : null,
        ];
    }

    /**
     * @return array{
     *     display_character_class_id: int|null,
     *     description: string|null,
     *     background_image_url: string|null,
     *     display_job: array<string, mixed>|null
     * }
     */
    private function serializeHomeProfile(?UserHomeProfile $homeProfile): array
    {
        return [
            'display_character_class_id' => $homeProfile?->display_character_class_id,
            'description' => $homeProfile?->description,
            'background_image_url' => $homeProfile?->background_image_url,
            'display_job' => $homeProfile?->displayCharacterClass
                ? $this->serializeCharacterClass($homeProfile->displayCharacterClass)
                : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeCharacterClassOptions(): array
    {
        return CharacterClass::query()
            ->orderBy('name')
            ->get(['id', 'name', 'shorthand', 'role', 'icon_url', 'flaticon_url'])
            ->map(fn (CharacterClass $characterClass) => $this->serializeCharacterClass($characterClass))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCharacterClass(CharacterClass $characterClass): array
    {
        return [
            'id' => $characterClass->id,
            'name' => $characterClass->name,
            'shorthand' => $characterClass->shorthand,
            'role' => $characterClass->role,
            'icon_url' => $characterClass->icon_url,
            'flaticon_url' => $characterClass->flaticon_url,
        ];
    }
}
