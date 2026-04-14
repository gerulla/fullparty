<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCharacterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // TODO: Implement authorization logic
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'world' => ['required', 'string', 'max:255'],
            'datacenter' => ['required', 'string', Rule::in(config('datacenters.values', []))],
            'lodestone_id' => ['required', 'string', 'unique:characters,lodestone_id'],
            'avatar_url' => ['nullable', 'url', 'max:500'],
            'token' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],

            // Dynamic field values (optional)
            'field_values' => ['nullable', 'array'],
            'field_values.*.field_definition_id' => ['required_with:field_values', 'exists:character_field_definitions,id'],
            'field_values.*.value' => ['nullable'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lodestone_id' => 'Lodestone ID',
            'avatar_url' => 'avatar URL',
            'datacenter' => 'data center',
        ];
    }
}
