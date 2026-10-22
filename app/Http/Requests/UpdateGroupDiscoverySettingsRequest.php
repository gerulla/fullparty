<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

class UpdateGroupDiscoverySettingsRequest extends GroupDetailsRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return Arr::only($this->baseRules(), [
            'primary_focuses',
            'experience_expectation',
            'voice_expectation',
            'preferred_languages',
            'tags',
            'tags.*',
            'active_timezone',
            'active_days',
            'active_start_time',
            'active_end_time',
        ]);
    }
}
