<?php

namespace App\Http\Requests\Resources;

use App\Models\GroupResource;
use App\Policies\GroupResourcePolicy;
use Illuminate\Foundation\Http\FormRequest;

class ResourceMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $group = $this->route('group');
        $resource = $this->route('resource');
        abort_if($resource instanceof GroupResource && (int) $resource->group_id !== (int) $group->id, 404);

        return app(GroupResourcePolicy::class)->manageLibrary($this->user(), $group);
    }

    public function rules(): array
    {
        $action = $this->route('operation');

        return [
            'version' => [$this->route('resource') ? 'required' : 'sometimes', 'integer', 'min:1'],
            'editing_token' => ['sometimes', 'string', 'size:64'],
            'content' => [! $this->route('resource') || $action === 'save' ? 'required' : 'sometimes', 'array'],
            'collection_id' => [! $this->route('resource') || $action === 'organize' ? 'required' : 'sometimes', 'integer'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'is_pinned' => ['sometimes', 'boolean'],
            'summary' => [$action === 'submit' ? 'required' : 'sometimes', 'string', 'max:300', 'regex:/^[^\r\n]+$/u'],
            'revision_id' => [$action === 'restore' ? 'required' : 'sometimes', 'integer'],
        ];
    }
}
