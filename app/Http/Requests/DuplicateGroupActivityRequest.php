<?php

namespace App\Http\Requests;

use App\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DuplicateGroupActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.Activity::TITLE_MAX_LENGTH],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i', 'after_or_equal:now'],
            'status' => ['required', Rule::in([
                Activity::STATUS_DRAFT,
                Activity::STATUS_SCHEDULED,
            ])],
            'copy_bench' => ['required', 'boolean'],
            'copy_fill_ins' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'starts_at.after_or_equal' => __('groups.activities.management.duplicate.future_error'),
        ];
    }
}
