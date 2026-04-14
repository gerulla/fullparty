<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
			'datacenter' => ['required', 'string', \Illuminate\Validation\Rule::in(config('datacenters.values', []))],
			'lodestone_id' => ['required', 'string'],
			'avatar_url' => ['nullable', 'url', 'max:500'],
			'token' => ['nullable', 'string', 'max:255'],
			'expires_at' => ['nullable', 'date'],
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
