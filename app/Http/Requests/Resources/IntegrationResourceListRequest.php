<?php

namespace App\Http\Requests\Resources;

class IntegrationResourceListRequest extends IntegrationResourceRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
