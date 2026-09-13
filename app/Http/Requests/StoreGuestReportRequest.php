<?php

namespace App\Http\Requests;

use App\Services\Moderation\PublicReportAccess;
use Illuminate\Validation\Rule;

class StoreGuestReportRequest extends StoreContentReportRequest
{
    public function authorize(): bool
    {
        return $this->routeIs('public-resources.reports.store');
    }

    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'target_type' => ['required', Rule::in(PublicReportAccess::TYPES)],
        ]);
    }
}
