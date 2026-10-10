<?php

namespace App\Services\Notifications;

use App\DTOs\Images\ImageTransformOptions;
use App\Models\Activity;
use App\Models\Character;
use App\Services\Images\ImageVariantService;
use App\Support\Activities\ActivityDisplayName;

class ActivityNotificationPayloadBuilder
{
    public function __construct(
        private readonly ImageVariantService $imageVariants,
        private readonly NotificationActionUrlService $actionUrls,
    ) {}

    /** @return array<string, mixed> */
    public function forActivity(?Activity $activity): array
    {
        $activity?->loadMissing(['group', 'activityTypeVersion']);

        return [
            'run_title' => ActivityDisplayName::for($activity),
            'group_name' => $activity?->group?->name,
            'group_icon_url' => $this->imageVariants->url(
                $activity?->group?->profile_picture_url,
                new ImageTransformOptions(width: 256, height: 256),
            ),
            'banner_image_url' => $this->imageVariants->url(
                $activity?->activityTypeVersion?->banner_image_url,
                new ImageTransformOptions(width: 1200, height: 400, fit: 'crop', upscale: true),
            ),
            'run_url' => $this->runUrl($activity),
            'discord_url' => $this->publicUrl($activity?->group?->discord_invite_url),
            'starts_at' => $activity?->starts_at?->toIso8601String(),
        ];
    }

    /** @return array{character_world: ?string, character_avatar_url: ?string} */
    public function forCharacter(?Character $character, ?string $savedWorld = null, ?string $savedAvatar = null): array
    {
        return [
            'character_world' => $character?->world ?: ($savedWorld ?: null),
            'character_avatar_url' => $this->publicUrl($character?->avatar_url ?: $savedAvatar),
        ];
    }

    public function runUrl(?Activity $activity): ?string
    {
        $activity?->loadMissing('group');
        if (! $activity?->group) {
            return null;
        }

        return $this->actionUrls->forBrowserLocalePreference(route('groups.activities.overview', [
            'group' => $activity->group->slug,
            'activity' => $activity->id,
        ]));
    }

    public function publicUrl(?string $value): ?string
    {
        if (blank($value) || str_contains($value, '\\')) {
            return null;
        }
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            $value = rtrim(config('app.url'), '/').$value;
        }
        $parts = parse_url($value);

        return is_array($parts)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && isset($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && filter_var($value, FILTER_VALIDATE_URL) !== false
                ? $value : null;
    }
}
