<?php

namespace App\Http\Requests\Resources;

use App\Policies\GroupResourcePolicy;
use Illuminate\Foundation\Http\FormRequest;

class ResourceCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(GroupResourcePolicy::class)->manageLibrary($this->user(), $this->route('group'));
    }

    public function rules(): array
    {
        return [
            'name' => [$this->route('collection') ? 'sometimes' : 'required', 'string', 'max:160', 'regex:/\A[A-Za-z0-9 .(){}\[\];_&-]+\z/'],
            'slug' => [$this->route('collection') ? 'sometimes' : 'required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'parent_id' => ['nullable', 'integer'],
            'icon' => ['nullable', 'string', 'max:100', 'regex:/^i-lucide-[a-z0-9-]+$/'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'is_featured' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['name.regex' => __('resource_errors.collection_name_invalid')];
    }
}
