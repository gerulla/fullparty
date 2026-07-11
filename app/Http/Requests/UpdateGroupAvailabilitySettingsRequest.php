<?php

namespace App\Http\Requests;

use App\Models\GroupAvailabilitySetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGroupAvailabilitySettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'minimum_role' => ['required', Rule::in(GroupAvailabilitySetting::MINIMUM_ROLES)],
        ];
    }
}
