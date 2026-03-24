<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
			'username' => ['required', 'string', 'min:3', 'max:32', 'unique:users,name'],
			'email' => ['required', 'string', 'email:rfc,dns', 'max:255', 'unique:users,email'],
			'password' => ['required', 'confirmed', Password::defaults()],
		];
	}
	
	/**
	 * @return array<string, string>
	 */
	public function attributes(): array
	{
		return [
			'username' => 'username',
			'email' => 'email address',
			'password' => 'password',
		];
	}
}
