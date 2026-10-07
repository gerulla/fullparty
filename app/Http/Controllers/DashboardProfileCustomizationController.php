<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDashboardProfileCustomizationRequest;
use App\Services\ManagedImageStorage;
use App\Support\Integrations\MemberApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class DashboardProfileCustomizationController extends Controller
{
    private const IMAGE_DIRECTORY = 'home-profiles';

    public function __construct(
        private readonly ManagedImageStorage $managedImageStorage,
    ) {}

    public function update(UpdateDashboardProfileCustomizationRequest $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();
        $homeProfile = $request->user()->homeProfile()->firstOrNew();

        $backgroundImageUrl = $homeProfile->background_image_url;

        if ((bool) ($validated['reset_background_image'] ?? false)) {
            $this->managedImageStorage->deleteManagedImage($backgroundImageUrl, self::IMAGE_DIRECTORY);
            $backgroundImageUrl = null;
        } else {
            $backgroundImageUrl = $this->managedImageStorage->replaceUploadedImageIfPresent(
                currentUrl: $backgroundImageUrl,
                file: $request->file('background_image'),
                directory: self::IMAGE_DIRECTORY,
            );
        }

        $homeProfile->fill([
            'display_character_class_id' => $validated['display_character_class_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'background_image_url' => $backgroundImageUrl,
        ]);

        $homeProfile->user()->associate($request->user());
        $homeProfile->save();

        return MemberApi::saved('dashboard_profile_updated');
    }
}
