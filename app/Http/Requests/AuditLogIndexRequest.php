<?php

namespace App\Http\Requests;

use App\Models\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AuditLogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $group = $this->route('group');

        return $group instanceof Group
            ? $group->hasModeratorAccess($this->user()?->id)
            : (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        $group = $this->route('group');

        return [
            'search' => ['nullable', 'string', 'max:200'],
            'action' => ['nullable', 'string', 'max:255'],
            'severity' => ['nullable', 'string', 'max:50'],
            'user' => ['nullable', 'regex:/^(?:[1-9][0-9]*|__system__)$/'],
            'group' => ['nullable', 'integer', 'min:1'],
            'activity' => $group instanceof Group
                ? ['nullable', 'integer', Rule::exists('activities', 'id')->where('group_id', $group->id)]
                : ['prohibited'],
            'beforeDate' => ['nullable', 'date_format:Y-m-d'],
            'afterDate' => ['nullable', 'date_format:Y-m-d'],
            'category' => ['nullable', Rule::in(['runs', 'members', 'resources', 'settings'])],
            'timezone' => ['nullable', 'timezone:all'],
            'cursor' => ['nullable', 'string', 'max:1000'],
            'include_options' => ['sometimes', 'boolean'],
        ];
    }

    public function filters(): array
    {
        return array_filter($this->safe()->except(['cursor', 'include_options']), fn ($value) => filled($value));
    }
}
