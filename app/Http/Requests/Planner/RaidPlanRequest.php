<?php

namespace App\Http\Requests\Planner;

use App\Models\RaidPlan;
use App\Models\RaidPlanMechanic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RaidPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'fight_id' => [
                'nullable',
                'integer',
                Rule::exists('activity_types', 'id')
                    ->where('is_active', true)
                    ->whereNotNull('current_published_version_id'),
            ],
            'visibility' => ['required', 'string', Rule::in(RaidPlan::VISIBILITIES)],
            'mechanics' => ['sometimes', 'array', 'max:200'],
            'mechanics.*.id' => ['nullable', 'integer'],
            'mechanics.*.name' => ['required', 'string', 'max:150'],
            'mechanics.*.type' => ['required', 'string', Rule::in(RaidPlanMechanic::TYPES)],
            'mechanics.*.duration_ms' => ['sometimes', 'integer', 'min:0', 'max:86400000'],
            'mechanics.*.is_enabled' => ['sometimes', 'boolean'],
            'mechanics.*.timeline' => ['sometimes', 'array'],
            'mechanics.*.timeline.components' => ['sometimes', 'array', 'max:25'],
            'mechanics.*.timeline.components.*.id' => ['required', 'string', 'max:100'],
            'mechanics.*.timeline.components.*.type' => ['required', Rule::in(['arena_map', 'boss', 'marker', 'marker_layout'])],
            'mechanics.*.timeline.components.*.marker_key' => [
                'required_if:mechanics.*.timeline.components.*.type,marker',
                Rule::in(['1', '2', '3', '4', 'A', 'B', 'C', 'D']),
            ],
            'mechanics.*.timeline.components.*.layout' => [
                'required_if:mechanics.*.timeline.components.*.type,marker_layout',
                Rule::in(['standard', 'standard_flipped', 'diamond', 'square', 'waymark_studio']),
            ],
            'mechanics.*.timeline.components.*.distance' => [
                'required_if:mechanics.*.timeline.components.*.type,marker_layout',
                'numeric',
                'between:20,500',
            ],
            'mechanics.*.timeline.components.*.waymark_preset' => [
                'nullable',
                'required_if:mechanics.*.timeline.components.*.layout,waymark_studio',
                'string',
                'json',
                'max:10000',
            ],
            'mechanics.*.timeline.components.*.image_url' => ['nullable', 'string', 'max:2048'],
            'mechanics.*.timeline.components.*.display_mode' => ['sometimes', Rule::in(['fit', 'fill', 'crop'])],
            'mechanics.*.timeline.components.*.offset_x' => ['required', 'numeric', 'between:-1280,1280'],
            'mechanics.*.timeline.components.*.offset_y' => ['required', 'numeric', 'between:-720,720'],
            'mechanics.*.timeline.components.*.rotation' => ['required', 'numeric', 'between:-360,360'],
            'mechanics.*.timeline.components.*.crop_left' => ['sometimes', 'numeric', 'between:0,99'],
            'mechanics.*.timeline.components.*.crop_right' => ['sometimes', 'numeric', 'between:0,99'],
            'mechanics.*.timeline.components.*.crop_top' => ['sometimes', 'numeric', 'between:0,99'],
            'mechanics.*.timeline.components.*.crop_bottom' => ['sometimes', 'numeric', 'between:0,99'],
            'mechanics.*.timeline.components.*.scale' => ['sometimes', 'numeric', 'between:0.1,5'],
            'mechanics.*.timeline.components.*.color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'mechanics.*.timeline.components.*.hitbox_style' => ['sometimes', Rule::in(['positionals', 'no_positionals'])],
            'mechanics.*.timeline_schema_version' => ['sometimes', 'integer', 'min:1'],
            'mechanics.*.variants' => ['sometimes', 'array', 'max:50'],
            'mechanics.*.variants.*.id' => ['nullable', 'integer'],
            'mechanics.*.variants.*.name' => ['required', 'string', 'max:150'],
            'mechanics.*.variants.*.duration_ms' => ['sometimes', 'integer', 'min:0', 'max:86400000'],
            'mechanics.*.variants.*.selection_weight' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'mechanics.*.variants.*.is_enabled' => ['sometimes', 'boolean'],
            'mechanics.*.variants.*.timeline' => ['sometimes', 'array'],
            'mechanics.*.variants.*.timeline.components' => ['sometimes', 'array', 'max:25'],
            'mechanics.*.variants.*.timeline.components.*.id' => ['required', 'string', 'max:100'],
            'mechanics.*.variants.*.timeline.components.*.type' => ['required', Rule::in(['arena_map', 'boss', 'marker', 'marker_layout'])],
            'mechanics.*.variants.*.timeline.components.*.marker_key' => [
                'required_if:mechanics.*.variants.*.timeline.components.*.type,marker',
                Rule::in(['1', '2', '3', '4', 'A', 'B', 'C', 'D']),
            ],
            'mechanics.*.variants.*.timeline.components.*.layout' => [
                'required_if:mechanics.*.variants.*.timeline.components.*.type,marker_layout',
                Rule::in(['standard', 'standard_flipped', 'diamond', 'square', 'waymark_studio']),
            ],
            'mechanics.*.variants.*.timeline.components.*.distance' => [
                'required_if:mechanics.*.variants.*.timeline.components.*.type,marker_layout',
                'numeric',
                'between:20,500',
            ],
            'mechanics.*.variants.*.timeline.components.*.waymark_preset' => [
                'nullable',
                'required_if:mechanics.*.variants.*.timeline.components.*.layout,waymark_studio',
                'string',
                'json',
                'max:10000',
            ],
            'mechanics.*.variants.*.timeline.components.*.image_url' => ['nullable', 'string', 'max:2048'],
            'mechanics.*.variants.*.timeline.components.*.display_mode' => ['sometimes', Rule::in(['fit', 'fill', 'crop'])],
            'mechanics.*.variants.*.timeline.components.*.offset_x' => ['required', 'numeric', 'between:-1280,1280'],
            'mechanics.*.variants.*.timeline.components.*.offset_y' => ['required', 'numeric', 'between:-720,720'],
            'mechanics.*.variants.*.timeline.components.*.rotation' => ['required', 'numeric', 'between:-360,360'],
            'mechanics.*.variants.*.timeline.components.*.crop_left' => ['sometimes', 'numeric', 'between:0,99'],
            'mechanics.*.variants.*.timeline.components.*.crop_right' => ['sometimes', 'numeric', 'between:0,99'],
            'mechanics.*.variants.*.timeline.components.*.crop_top' => ['sometimes', 'numeric', 'between:0,99'],
            'mechanics.*.variants.*.timeline.components.*.crop_bottom' => ['sometimes', 'numeric', 'between:0,99'],
            'mechanics.*.variants.*.timeline.components.*.scale' => ['sometimes', 'numeric', 'between:0.1,5'],
            'mechanics.*.variants.*.timeline.components.*.color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'mechanics.*.variants.*.timeline.components.*.hitbox_style' => ['sometimes', Rule::in(['positionals', 'no_positionals'])],
            'mechanics.*.variants.*.timeline_schema_version' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
