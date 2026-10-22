<?php

namespace App\Http\Requests;

use App\Models\Activity;
use App\Models\Group;
use App\Services\Runs\RunAccessService;
use App\Support\Input\RequestTextInputSanitizer;
use Illuminate\Foundation\Http\FormRequest;

final class GuestSelfAssignmentRequest extends FormRequest
{
    public function authorize(RunAccessService $access): bool
    {
        $group = $this->route('group');
        $activity = $this->route('activity');
        abort_unless($this->user() === null && $group instanceof Group && $activity instanceof Activity
            && $access->canSelfAssign($group, $activity, null), 404);

        return true;
    }

    protected function prepareForValidation(): void
    {
        app(RequestTextInputSanitizer::class)->sanitize($this, [
            'guest_applicant.name', 'guest_applicant.world', 'guest_applicant.datacenter',
        ]);
    }

    public function rules(): array
    {
        return [
            'guest_applicant' => ['required', 'array:lodestone_id,name,world,datacenter,avatar_url'],
            'guest_applicant.lodestone_id' => ['required', 'string', 'regex:/^[0-9]{1,20}$/'],
            'guest_applicant.name' => ['required', 'string', 'max:255'],
            'guest_applicant.world' => ['required', 'string', 'max:255'],
            'guest_applicant.datacenter' => ['required', 'string', 'max:255'],
            'guest_applicant.avatar_url' => ['nullable', 'url', 'max:2048'],
            'field_values' => ['sometimes', 'array'],
            'expected_slot_state_token' => ['required', 'string'],
        ];
    }
}
