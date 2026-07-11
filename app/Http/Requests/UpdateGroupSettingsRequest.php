<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

class UpdateGroupSettingsRequest extends GroupDetailsRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            ...Arr::only($this->baseRules(), [
                'name',
                'description',
                'profile_picture',
                'banner_image',
                'discord_invite_url',
                'datacenter',
                'join_mode',
                'is_visible',
            ]),
            'features' => ['sometimes', 'array'],
            'features.availability_scheduler_enabled' => ['sometimes', 'boolean'],
            'features.statistics_enabled' => ['sometimes', 'boolean'],
            'features.leaderboard_enabled' => ['sometimes', 'boolean'],
            'features.calendar_sync_enabled' => ['sometimes', 'boolean'],
            'features.resource_hub_enabled' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (! $this->exists('features') || ! is_array($this->input('features'))) {
            return;
        }

        $features = $this->input('features');

        foreach ([
            'availability_scheduler_enabled',
            'statistics_enabled',
            'leaderboard_enabled',
            'calendar_sync_enabled',
            'resource_hub_enabled',
        ] as $field) {
            if (! array_key_exists($field, $features)) {
                continue;
            }

            $value = $features[$field];

            if (is_bool($value)) {
                continue;
            }

            $features[$field] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value;
        }

        $this->merge(['features' => $features]);
    }
}
