<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModerationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['claim', 'hide', 'restore', 'ban', 'unban', 'dismiss', 'reopen'])],
            'reason' => ['nullable', 'required_unless:action,claim', 'string', 'max:2000'],
        ];
    }
}
