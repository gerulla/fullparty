<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCharacterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // TODO: Implement authorization logic
        // Example: return $this->user()->can('update', $this->route('character'));
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $characterId = $this->route('character')?->id ?? $this->route('character');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'world' => ['sometimes', 'string', 'max:255'],
            'datacenter' => ['sometimes', 'string', 'max:255'],
            'lodestone_id' => [
                'sometimes',
                'string',
                Rule::unique('characters', 'lodestone_id')->ignore($characterId),
            ],
            'avatar_url' => ['nullable', 'url', 'max:500'],
            'token' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
            'verified_at' => ['nullable', 'date'],

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
