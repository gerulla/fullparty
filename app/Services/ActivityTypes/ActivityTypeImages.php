<?php

namespace App\Services\ActivityTypes;

use App\Models\ActivityType;
use App\Services\ManagedImageStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ActivityTypeImages
{
    private const DIRECTORY = 'activity-types';

    public function __construct(private readonly ManagedImageStorage $images) {}

    public function draftAttributes(ActivityType $type, array $attributes): array
    {
        foreach (['draft_small_image', 'draft_banner_image'] as $field) {
            $current = $type->{$field.'_url'};
            $upload = $this->upload($attributes[$field] ?? null);
            $attributes[$field.'_url'] = $upload ?? $current;
            unset($attributes[$field]);

            if ($upload !== null && $current !== null) {
                $this->deleteAfterCommit($current);
            }
        }

        if (array_key_exists('draft_prog_points', $attributes)) {
            $previousPoints = $type->draft_prog_points ?? [];
            $previousByKey = array_column($previousPoints, null, 'key');
            $existingUrls = array_column($previousPoints, 'banner_image_url');
            $points = $attributes['draft_prog_points'];

            foreach ($points ?? [] as $index => $point) {
                // The editor returns existing URLs so a banner follows a renamed or reordered point.
                $url = array_key_exists('banner_image_url', $point)
                    ? $point['banner_image_url']
                    : ($previousByKey[$point['key']]['banner_image_url'] ?? null);

                if ($url !== null && ! in_array($url, $existingUrls, true)) {
                    $attribute = "draft_prog_points.$index.banner_image_url";
                    throw ValidationException::withMessages([$attribute => __('validation.in', ['attribute' => $attribute])]);
                }

                $banner = $this->upload($point['banner_image'] ?? null) ?? $url;
                unset($point['banner_image'], $point['banner_image_url']);
                if ($banner !== null) {
                    $point['banner_image_url'] = $banner;
                }
                $points[$index] = $point;
            }

            foreach (array_diff(array_filter($existingUrls), array_column($points ?? [], 'banner_image_url')) as $obsolete) {
                $this->deleteAfterCommit($obsolete);
            }
            $attributes['draft_prog_points'] = $points;
        }

        return $attributes;
    }

    public function copy(?string $url): ?string
    {
        $copy = $this->images->copyManagedImage($url, self::DIRECTORY);
        if ($copy !== null && $copy !== $url) {
            $this->deleteAfterRollback($copy);
        }

        return $copy;
    }

    public function copyProgPoints(?array $points): ?array
    {
        foreach ($points ?? [] as $index => $point) {
            if (isset($point['banner_image_url'])) {
                $points[$index]['banner_image_url'] = $this->copy($point['banner_image_url']);
            }
        }

        return $points;
    }

    private function upload(?UploadedFile $file): ?string
    {
        $url = $this->images->uploadImageIfPresent($file, self::DIRECTORY);
        if ($url !== null) {
            $this->deleteAfterRollback($url);
        }

        return $url;
    }

    private function deleteAfterRollback(string $url): void
    {
        DB::afterRollBack(fn () => $this->images->deleteManagedImage($url, self::DIRECTORY));
    }

    private function deleteAfterCommit(string $url): void
    {
        DB::afterCommit(fn () => $this->images->deleteManagedImage($url, self::DIRECTORY));
    }
}
