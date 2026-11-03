<?php

namespace App\Http\Requests;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Services\Runs\RunDiscoveryService;
use App\Support\Input\RequestTextInputSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RunDiscoveryFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'query' => ['nullable', 'string', 'max:255'],
            'saved_only' => ['nullable', 'boolean'],
            'activity_category' => ['nullable', 'string', Rule::in(ActivityType::DIFFICULTIES)],
            'activity_type' => ['nullable', 'string', Rule::in($this->availableActivityTypeSlugs())],
            'prog_point' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', Rule::in($this->availableRegions())],
            'datacenter' => ['nullable', 'string', Rule::in(config('datacenters.values', []))],
            'group' => ['nullable', 'string', Rule::in($this->availableGroupSlugs())],
            'timezone' => ['required', 'string', 'timezone'],
            'date_range' => ['nullable', 'string', Rule::in(['upcoming', 'next_7_days', 'next_30_days', 'this_week', 'next_week', 'custom_range', 'custom_day'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'time_of_day' => ['nullable', 'string', Rule::in(['any', 'morning', 'afternoon', 'evening', 'night'])],
            'run_style' => ['nullable', 'string', Rule::in(Activity::RUN_STYLES)],
            'beginner_friendly' => ['nullable', 'boolean'],
            'language' => ['nullable', 'string', Rule::in(config('group_discovery.preferred_languages', []))],
            'role_category' => ['nullable', 'string', Rule::in(['any', 'tank', 'healer', 'dps'])],
            'class_keys' => ['nullable', 'array', 'max:24'],
            'class_keys.*' => ['required', 'string', Rule::in($this->availableClassKeys())],
            'group_type' => ['nullable', 'string', Rule::in(Group::TYPES)],
            'application_status' => ['nullable', 'string', Rule::in(['applications_open', 'direct_join'])],
            'intensity' => ['nullable', 'string', Rule::in(Activity::INTENSITIES)],
            'voice_expectation' => ['nullable', 'string', Rule::in(config('group_discovery.voice_expectations', []))],
            'sort' => ['nullable', 'string', Rule::in(['starting_soonest', 'newest_posted', 'recently_updated', 'open_slots'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        app(RequestTextInputSanitizer::class)->sanitize(
            $this,
            [
                'query',
                'activity_category',
                'activity_type',
                'prog_point',
                'region',
                'datacenter',
                'group',
                'timezone',
                'date_range',
                'date_from',
                'date_to',
                'time_of_day',
                'run_style',
                'language',
                'role_category',
                'class_keys.*',
                'group_type',
                'application_status',
                'intensity',
                'voice_expectation',
                'sort',
            ],
        );

        $normalized = [
            'class_keys' => $this->normalizeStringArray($this->input('class_keys')),
        ];

        foreach ([
            'activity_category',
            'activity_type',
            'prog_point',
            'region',
            'datacenter',
            'group',
            'time_of_day',
            'run_style',
            'language',
            'role_category',
            'group_type',
            'application_status',
            'intensity',
            'voice_expectation',
            'sort',
        ] as $field) {
            if ($this->filled($field) && in_array($this->input($field), ['any', 'all'], true)) {
                $normalized[$field] = null;
            }
        }

        if ($this->exists('beginner_friendly')) {
            $normalized['beginner_friendly'] = $this->boolean('beginner_friendly');
        }

        if ($this->exists('saved_only')) {
            $normalized['saved_only'] = $this->boolean('saved_only');
        }

        $this->merge($normalized);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $dateRange = $this->input('date_range');
            $dateFrom = $this->input('date_from');
            $dateTo = $this->input('date_to');

            if ($dateRange === 'custom_day' && (! is_string($dateFrom) || $dateFrom === '')) {
                $validator->errors()->add('date_from', __('validation.required'));
            }

            if ($dateRange === 'custom_range') {
                if (! is_string($dateFrom) || $dateFrom === '') {
                    $validator->errors()->add('date_from', __('validation.required'));
                }

                if (! is_string($dateTo) || $dateTo === '') {
                    $validator->errors()->add('date_to', __('validation.required'));
                }
            }

            $activityTypeSlug = $this->input('activity_type');
            $progPoint = $this->input('prog_point');

            if (! is_string($activityTypeSlug) || $activityTypeSlug === '' || ! is_string($progPoint) || $progPoint === '') {
                return;
            }

            $progPointKeys = collect($this->activityTypeProgPoints()[$activityTypeSlug] ?? [])
                ->pluck('key')
                ->filter(fn (mixed $value) => is_string($value) && $value !== '')
                ->all();

            if (! in_array($progPoint, $progPointKeys, true)) {
                $validator->errors()->add('prog_point', __('validation.in'));
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private function availableActivityTypeSlugs(): array
    {
        return ActivityType::query()
            ->where('is_active', true)
            ->whereNotNull('current_published_version_id')
            ->pluck('slug')
            ->filter(fn (mixed $value) => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function availableRegions(): array
    {
        return array_values(array_unique(array_filter(array_values(config('datacenters.regions', [])))));
    }

    /**
     * @return array<int, string>
     */
    private function availableClassKeys(): array
    {
        return CharacterClass::query()
            ->pluck('shorthand')
            ->filter(fn (mixed $value) => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function availableGroupSlugs(): array
    {
        return app(RunDiscoveryService::class)->filterableGroupsForUser($this->user()?->id)
            ->pluck('slug')
            ->filter(fn (mixed $value) => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function activityTypeProgPoints(): array
    {
        return ActivityType::query()
            ->with('currentPublishedVersion:id,activity_type_id,prog_points')
            ->where('is_active', true)
            ->whereNotNull('current_published_version_id')
            ->get(['id', 'slug', 'current_published_version_id'])
            ->mapWithKeys(fn (ActivityType $activityType) => [
                $activityType->slug => Arr::wrap($activityType->currentPublishedVersion?->prog_points),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_values(array_filter($value, fn (mixed $entry) => is_string($entry) && $entry !== ''))));
    }
}
