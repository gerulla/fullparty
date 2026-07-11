<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublishActivityPartyFinderInfoRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'character_name' => is_string($this->input('character_name'))
                ? trim($this->input('character_name'))
                : $this->input('character_name'),
            'world' => is_string($this->input('world'))
                ? trim($this->input('world'))
                : $this->input('world'),
            'password' => is_string($this->input('password'))
                ? trim($this->input('password'))
                : $this->input('password'),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'character_name' => ['required', 'string', 'max:64'],
            'world' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:32'],
        ];
    }
}
