<?php

namespace App\Services\ActivityTypes;

use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ManagedImageStorage;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ActivityTypePublishingService
{
    private const IMAGE_DIRECTORY = 'activity-types';

    public function __construct(
        private readonly ActivityTypeDraftValidator $draftValidator,
        private readonly ManagedImageStorage $imageStorage,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function publish(ActivityType $activityType, User $publisher): ActivityTypeVersion
    {
        return $this->publishVersions($publisher, $activityType->id)->first();
    }

    public function publishAll(User $publisher): int
    {
        return $this->publishVersions($publisher)->count();
    }

    /** @return Collection<int, ActivityTypeVersion> */
    private function publishVersions(User $publisher, ?int $activityTypeId = null): Collection
    {
        $copiedImages = [];

        try {
            return DB::transaction(function () use ($publisher, $activityTypeId, &$copiedImages): Collection {
                // Single and bulk publishing share these locks and version numbering.
                $query = ActivityType::query()->orderBy('id')->lockForUpdate();
                $types = $activityTypeId === null
                    ? $query->get()
                    : collect([$query->findOrFail($activityTypeId)]);

                // Validate the entire batch before creating versions or copying images.
                foreach ($types as $type) {
                    try {
                        $this->draftValidator->validate($type->attributesToArray());
                    } catch (ValidationException $exception) {
                        if ($activityTypeId !== null) {
                            throw $exception;
                        }

                        throw ValidationException::withMessages([
                            'publish_all' => __('admin.activity_types.publish_all_invalid', [
                                'activity' => $type->draft_name['en'] ?? $type->slug,
                                'reason' => collect($exception->errors())->flatten()->first(),
                            ]),
                        ]);
                    }
                }

                return $types->map(function (ActivityType $type) use ($publisher, &$copiedImages): ActivityTypeVersion {
                    return $this->createVersion($type, $publisher, $copiedImages);
                });
            });
        } catch (Throwable $exception) {
            // Files are outside the database transaction; remove only new snapshot copies.
            foreach ($copiedImages as $url) {
                $this->imageStorage->deleteManagedImage($url, self::IMAGE_DIRECTORY);
            }

            throw $exception;
        }
    }

    private function createVersion(ActivityType $type, User $publisher, array &$copiedImages): ActivityTypeVersion
    {
        $version = $type->versions()->create([
            'version' => ((int) $type->versions()->max('version')) + 1,
            'name' => $type->draft_name,
            'description' => $type->draft_description,
            'small_image_url' => $this->copyImage($type->draft_small_image_url, $copiedImages),
            'banner_image_url' => $this->copyImage($type->draft_banner_image_url, $copiedImages),
            'difficulty' => $type->draft_difficulty ?? ActivityType::DIFFICULTY_NORMAL,
            'default_min_item_level' => $type->draft_default_min_item_level,
            'layout_schema' => $type->draft_layout_schema,
            'slot_schema' => $type->draft_slot_schema,
            'application_schema' => $type->draft_application_schema,
            'roster_summary_presets' => $type->draft_roster_summary_presets,
            'progress_schema' => $type->draft_progress_schema,
            'bench_size' => $type->draft_bench_size,
            'prog_points' => $type->draft_prog_points,
            'fflogs_zone_id' => $type->draft_fflogs_zone_id,
            'published_by_user_id' => $publisher->id,
            'published_at' => now(),
        ]);

        $type->update(['current_published_version_id' => $version->id]);

        $this->auditLogger->log(
            action: 'admin.activity_type.published',
            severity: AuditSeverity::CRITICAL,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.activity_type.published',
            actor: $publisher,
            subject: $type,
            metadata: [
                'activity_type_version_id' => $version->id,
                'published_version' => $version->version,
                'slug' => $type->slug,
                'draft_name' => $type->draft_name,
                'activity_type_name' => trim($type->draft_name['en']),
            ],
        );

        return $version;
    }

    private function copyImage(?string $url, array &$copiedImages): ?string
    {
        $copy = $this->imageStorage->copyManagedImage($url, self::IMAGE_DIRECTORY);

        if ($copy !== null && $copy !== $url) {
            $copiedImages[] = $copy;
        }

        return $copy;
    }
}
