<?php

namespace App\Http\Requests\Planner;

use Illuminate\Foundation\Http\FormRequest;

class StoreRaidPlanImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
                'dimensions:max_width=4096,max_height=4096',
            ],
        ];
    }
}
