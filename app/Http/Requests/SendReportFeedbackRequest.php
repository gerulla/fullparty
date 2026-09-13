<?php

namespace App\Http\Requests;

use App\Services\Moderation\ReportFeedbackService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendReportFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'template' => ['required', Rule::in(ReportFeedbackService::TEMPLATES)],
            'message' => ['nullable', 'required_if:template,other', 'string', 'max:3000'],
        ];
    }
}
