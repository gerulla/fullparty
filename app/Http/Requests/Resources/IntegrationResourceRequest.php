<?php

namespace App\Http\Requests\Resources;

use Illuminate\Foundation\Http\FormRequest;

class IntegrationResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    public function rules(): array
    {
        return ['discord_guild_id' => ['required', 'string', 'regex:/^\d{1,32}$/']];
    }
}
