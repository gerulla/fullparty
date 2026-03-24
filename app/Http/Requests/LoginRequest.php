<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}
	
	/**
	 * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<int, mixed>|string>
	 */
	public function rules(): array
	{
		return [
			'email' => ['required', 'string', 'email:rfc,dns', 'max:255'],
			'password' => ['required', 'string'],
			'remember' => ['sometimes', 'boolean'],
		];
	}
	
	protected function prepareForValidation(): void
	{
		if ($this->has('email')) {
			$this->merge([
				'email' => strtolower(trim((string) $this->input('email'))),
			]);
		}
	}
}
