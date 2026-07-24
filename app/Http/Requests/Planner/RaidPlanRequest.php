<?php

namespace App\Http\Requests\Planner;

use App\Models\RaidPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RaidPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'fight_id' => [
                'nullable',
                'integer',
                Rule::exists('activity_types', 'id')
                    ->where('is_active', true)
                    ->whereNotNull('current_published_version_id'),
            ],
            'visibility' => ['required', 'string', Rule::in(RaidPlan::VISIBILITIES)],
        ];
    }
}
