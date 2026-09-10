<?php

namespace App\Http\Requests\Resources;

use App\Policies\GroupResourcePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteLibraryResourcesRequest extends FormRequest
{
    public function authorize(GroupResourcePolicy $policy): bool
    {
        return $policy->configure($this->user(), $this->route('group'));
    }

    public function rules(): array
    {
        return ['confirmation' => ['required', 'string', Rule::in(['i am sure'])]];
    }
}
