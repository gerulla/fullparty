<?php

namespace App\Http\Resources\XivPlugin;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotFieldValue;
use App\Models\Character;
use App\Models\PhantomJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class XivPluginRunResource extends JsonResource
{
    public function __construct(
        $resource,
        private readonly bool $canModerate = false,
        private readonly bool $includeRoster = false,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Activity $activity */
        $activity = $this->resource;
        $startsAt = $activity->starts_at;
        $durationMinutes = (int) round(($activity->duration_hours ?? Activity::DEFAULT_DURATION_HOURS) * 60);

        $payload = [
            'id' => $activity->id,
            'group_id' => $activity->group_id,
            'status' => $activity->status,
            'name' => $this->runDisplayName($activity),
            'title' => $activity->title,
            'starts_at' => $startsAt?->toIso8601String(),
            'ends_at' => $startsAt?->copy()->addMinutes($durationMinutes)->toIso8601String(),
            'duration_hours' => $activity->duration_hours,
            'duration_minutes' => $durationMinutes,
            'datacenter' => $activity->datacenter,
            'target_prog_point_key' => $activity->target_prog_point_key,
            'is_public' => $activity->is_public,
            'needs_application' => $activity->needs_application,
            'allow_guest_applications' => $activity->allow_guest_applications,
            'application_count' => $this->canModerate ? $this->activeApplicationCount($activity) : null,
            'activity_type' => [
                'id' => $activity->activity_type_id,
                'version_id' => $activity->activity_type_version_id,
                'name' => $activity->activityTypeVersion?->name ?? [],
                'display_name' => $this->localized($activity->activityTypeVersion?->name) ?? 'Activity',
                'difficulty' => $activity->activityTypeVersion?->difficulty,
                'small_image_url' => $activity->activityTypeVersion?->small_image_url,
                'banner_image_url' => $activity->activityTypeVersion?->banner_image_url,
            ],
        ];

        if (! $this->includeRoster) {
            return $payload;
        }

        $phantomJobsById = $this->phantomJobsById($activity);

        return array_merge($payload, [
            'can_moderate' => $this->canModerate,
            'roster' => [
                'slots' => $activity->slots
                    ->map(fn (ActivitySlot $slot): array => $this->slot($slot, $phantomJobsById))
                    ->values()
                    ->all(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function slot(ActivitySlot $slot, Collection $phantomJobsById): array
    {
        $activeAssignment = $slot->assignments->first();

        return [
            'id' => $slot->id,
            'slot_kind' => $slot->slot_kind,
            'group_key' => $slot->group_key,
            'group_label' => $slot->group_label ?? [],
            'filled_group_key' => $slot->filled_group_key,
            'filled_group_label' => $slot->filled_group_label ?? [],
            'slot_key' => $slot->slot_key,
            'slot_label' => $slot->slot_label ?? [],
            'position_in_group' => $slot->position_in_group,
            'sort_order' => $slot->sort_order,
            'is_bench' => $slot->slot_kind === ActivitySlot::SLOT_KIND_BENCH,
            'is_fill_in' => $slot->slot_kind === ActivitySlot::SLOT_KIND_FILL_IN,
            'is_host' => $slot->is_host,
            'is_raid_leader' => $slot->is_raid_leader,
            'assigned_character' => $this->character($slot->assignedCharacter),
            'field_values' => $slot->fieldValues
                ->map(fn (ActivitySlotFieldValue $fieldValue): array => $this->fieldValue($fieldValue, $phantomJobsById))
                ->values()
                ->all(),
            'assignment' => $activeAssignment ? [
                'id' => $activeAssignment->id,
                'application_id' => $this->canModerate ? $activeAssignment->application_id : null,
                'source' => $activeAssignment->assignment_source,
                'attendance_status' => $activeAssignment->attendance_status,
                'assigned_at' => $activeAssignment->assigned_at?->toIso8601String(),
                'checked_in_at' => $activeAssignment->checked_in_at?->toIso8601String(),
                'marked_missing_at' => $activeAssignment->marked_missing_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @param  Collection<int, PhantomJob>  $phantomJobsById
     * @return array<string, mixed>
     */
    private function fieldValue(ActivitySlotFieldValue $fieldValue, Collection $phantomJobsById): array
    {
        return [
            'field_key' => $fieldValue->field_key,
            'field_label' => $fieldValue->field_label ?? [],
            'field_type' => $fieldValue->field_type,
            'source' => $fieldValue->source,
            'value' => $fieldValue->source === 'phantom_jobs'
                ? $this->withPhantomJobIcons($fieldValue->value, $phantomJobsById)
                : $fieldValue->value,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function character(?Character $character): ?array
    {
        if (! $character) {
            return null;
        }

        return [
            'id' => $character->id,
            'name' => $character->name,
            'world' => $character->world,
            'datacenter' => $character->datacenter,
            'avatar_url' => $character->avatar_url,
            'user' => $character->user ? [
                'id' => $character->user->id,
                'name' => $character->user->name,
                'avatar_url' => $character->user->avatar_url,
            ] : null,
        ];
    }

    private function activeApplicationCount(Activity $activity): int
    {
        if ($activity->getAttribute('active_application_count') !== null) {
            return (int) $activity->getAttribute('active_application_count');
        }

        return $activity->applications()
            ->whereIn('status', ActivityApplication::ACTIVE_STATUSES)
            ->count();
    }

    /**
     * @return Collection<int, PhantomJob>
     */
    private function phantomJobsById(Activity $activity): Collection
    {
        $ids = $activity->slots
            ->flatMap(fn (ActivitySlot $slot) => $slot->fieldValues
                ->filter(fn (ActivitySlotFieldValue $fieldValue) => $fieldValue->source === 'phantom_jobs')
                ->flatMap(fn (ActivitySlotFieldValue $fieldValue) => $this->phantomJobIds($fieldValue->value)))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return PhantomJob::query()
            ->whereIn('id', $ids)
            ->get(['id', 'icon_url', 'black_icon_url', 'transparent_icon_url', 'sprite_url'])
            ->keyBy('id');
    }

    /**
     * @return Collection<int, int>
     */
    private function phantomJobIds(mixed $value): Collection
    {
        if (is_numeric($value)) {
            return collect([(int) $value]);
        }

        if (! is_array($value)) {
            return collect();
        }

        if (isset($value['id']) && is_numeric($value['id'])) {
            return collect([(int) $value['id']]);
        }

        return collect($value)
            ->flatMap(fn ($item) => $this->phantomJobIds($item));
    }

    /**
     * @param  Collection<int, PhantomJob>  $phantomJobsById
     */
    private function withPhantomJobIcons(mixed $value, Collection $phantomJobsById): mixed
    {
        if (is_numeric($value)) {
            return $this->phantomJobIconPayload(['id' => (int) $value], $phantomJobsById);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (isset($value['id']) && is_numeric($value['id'])) {
            return $this->phantomJobIconPayload($value, $phantomJobsById);
        }

        return collect($value)
            ->map(fn ($item) => $this->withPhantomJobIcons($item, $phantomJobsById))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  Collection<int, PhantomJob>  $phantomJobsById
     * @return array<string, mixed>
     */
    private function phantomJobIconPayload(array $value, Collection $phantomJobsById): array
    {
        $phantomJob = $phantomJobsById->get((int) $value['id']);

        if (! $phantomJob) {
            return $value;
        }

        return array_merge($value, [
            'icon_url' => $this->imageUrl($phantomJob->icon_url),
            'black_icon_url' => $this->imageUrl($phantomJob->black_icon_url),
            'transparent_icon_url' => $this->imageUrl($phantomJob->transparent_icon_url),
            'sprite_url' => $this->imageUrl($phantomJob->sprite_url),
        ]);
    }

    private function imageUrl(?string $path): ?string
    {
        if (! filled($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return asset(ltrim($path, '/'));
    }

    private function runDisplayName(Activity $activity): string
    {
        if (filled($activity->title)) {
            return (string) $activity->title;
        }

        return $this->localized($activity->activityTypeVersion?->name) ?? 'Activity #'.$activity->id;
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    private function localized(?array $value): ?string
    {
        if (! $value) {
            return null;
        }

        $preferred = $value['en'] ?? null;

        if (filled($preferred)) {
            return (string) $preferred;
        }

        $first = collect($value)->first(fn ($label) => filled($label));

        return filled($first) ? (string) $first : null;
    }
}
