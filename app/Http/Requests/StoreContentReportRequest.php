<?php

namespace App\Http\Requests;

use App\Models\ContentReport;
use App\Services\Moderation\ReportTargetRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContentReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'target_type' => ['required', Rule::in(ReportTargetRegistry::TYPES)],
            'target_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::in(ContentReport::REASONS)],
            'details' => ['nullable', 'required_if:reason,other', 'string', 'max:3000'],
        ];
    }
}
