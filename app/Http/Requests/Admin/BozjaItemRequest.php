<?php

namespace App\Http\Requests\Admin;

use App\Support\Bozja\BozjaItemCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BozjaItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $bozjaItemId = $this->route('bozjaItem')?->id;

        return [
            'key' => [
                'required',
                'string',
                'max:160',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('bozja_items', 'key')->ignore($bozjaItemId),
            ],
            'category' => ['required', 'string', Rule::in(BozjaItemCategory::VALUES)],
            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'max:255'],
            'name.de' => ['nullable', 'string', 'max:255'],
            'name.fr' => ['nullable', 'string', 'max:255'],
            'name.ja' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'array'],
            'description.*' => ['nullable', 'string', 'max:5000'],
            'classification' => ['required', 'string', 'max:40'],
            'cache_weight' => ['required', 'integer', 'min:0', 'max:65535'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['required', 'boolean'],
            'icon' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],
        ];
    }
}
