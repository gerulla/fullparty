<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GroupAvailabilitySelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $startsAt = CarbonImmutable::parse($this->string('starts_at')->toString());
                $endsAt = CarbonImmutable::parse($this->string('ends_at')->toString());

                if ($startsAt->diffInMinutes($endsAt) > 7 * 24 * 60) {
                    $validator->errors()->add('ends_at', __('groups.availability.validation.selection_too_long'));
                }
            },
        ];
    }
}
