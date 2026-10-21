<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddOnboardingCharacterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(['lodestone', 'xivauth'])],
            'lodestone_id' => ['required', 'string', 'regex:/^[0-9]{1,20}$/'],
        ];
    }
}
