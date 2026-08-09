<?php

namespace App\Http\Requests;

use App\Models\GroupQuickCreateShortcut;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateGroupQuickCreateShortcutsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'shortcuts' => ['required', 'array', 'min:1', 'max:'.GroupQuickCreateShortcut::MAX_SHORTCUTS],
            'shortcuts.*.time' => ['required', 'date_format:H:i'],
            'shortcuts.*.time_mode' => ['required', Rule::in(GroupQuickCreateShortcut::TIME_MODES)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $shortcuts = collect($this->input('shortcuts', []));
                $keys = $shortcuts
                    ->filter(fn (mixed $shortcut): bool => is_array($shortcut))
                    ->map(fn (array $shortcut): string => sprintf(
                        '%s:%s',
                        $shortcut['time_mode'] ?? '',
                        $shortcut['time'] ?? '',
                    ));

                if ($keys->duplicates()->isNotEmpty()) {
                    $validator->errors()->add('shortcuts', __('groups.shortcuts.validation.duplicate'));
                }
            },
        ];
    }
}
