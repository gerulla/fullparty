<?php

namespace App\Http\Requests;

use App\Support\Input\RequestTextInputSanitizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreXIVAuthCharacterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'world' => ['required', 'string', 'max:255'],
            'datacenter' => ['required', 'string', Rule::in(config('datacenters.values', []))],
            'lodestone_id' => ['required', 'string'],
            'avatar_url' => ['nullable', 'url', 'max:500'],
            'token' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        app(RequestTextInputSanitizer::class)->sanitize($this, [
            'name',
            'world',
            'datacenter',
        ]);
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lodestone_id' => __('validation.attributes.lodestone_id'),
            'avatar_url' => __('validation.attributes.avatar_url'),
            'datacenter' => __('validation.attributes.datacenter'),
        ];
    }
}
