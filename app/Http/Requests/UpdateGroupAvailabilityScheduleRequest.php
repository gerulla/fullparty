<?php

namespace App\Http\Requests;

use App\Models\GroupAvailabilityWindow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateGroupAvailabilityScheduleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'cycle_weeks' => ['required', 'integer', Rule::in([1, 2, 4])],
            'repeats' => ['required', 'boolean'],
            'lock_weekends' => ['required', 'boolean'],
            'on_hiatus' => ['required', 'boolean'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
            'windows' => ['present', 'array', 'max:224'],
            'windows.*.cycle_week' => ['required', 'integer', 'min:0', 'max:3'],
            'windows.*.weekday' => ['required', 'integer', 'between:1,7'],
            'windows.*.status' => ['required', Rule::in(GroupAvailabilityWindow::STATUSES)],
            'windows.*.starts_at' => ['required', 'date_format:H:i'],
            'windows.*.ends_at' => ['required', 'date_format:H:i'],
            'exceptions' => ['present', 'array', 'max:366'],
            'exceptions.*.date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'distinct'],
            'exceptions.*.starts_at' => ['nullable', 'date_format:H:i', 'required_with:exceptions.*.ends_at'],
            'exceptions.*.ends_at' => ['nullable', 'date_format:H:i', 'required_with:exceptions.*.starts_at'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $cycleWeeks = (int) $this->input('cycle_weeks', 1);
                $windowsByDay = collect($this->input('windows', []))
                    ->groupBy(fn (array $window) => sprintf('%d:%d', $window['cycle_week'] ?? -1, $window['weekday'] ?? -1));

                foreach ($this->input('windows', []) as $index => $window) {
                    if ((int) ($window['cycle_week'] ?? -1) >= $cycleWeeks) {
                        $validator->errors()->add(
                            "windows.{$index}.cycle_week",
                            __('groups.availability.validation.cycle_week'),
                        );
                    }

                    if (($window['starts_at'] ?? null) === ($window['ends_at'] ?? null)) {
                        $validator->errors()->add(
                            "windows.{$index}.ends_at",
                            __('groups.availability.validation.end_time'),
                        );
                    }
                }

                foreach ($windowsByDay as $windows) {
                    $normalized = $windows
                        ->map(function (array $window) {
                            $start = $this->minuteOfDay((string) ($window['starts_at'] ?? '00:00'));
                            $end = $this->minuteOfDay((string) ($window['ends_at'] ?? '00:00'));

                            return [$start, $end <= $start ? $end + 1440 : $end];
                        })
                        ->sortBy(fn (array $window) => $window[0])
                        ->values();

                    for ($index = 1; $index < $normalized->count(); $index++) {
                        if ($normalized[$index][0] < $normalized[$index - 1][1]) {
                            $validator->errors()->add('windows', __('groups.availability.validation.overlap'));
                            break 2;
                        }
                    }
                }

                foreach ($this->input('exceptions', []) as $index => $exception) {
                    if (filled($exception['starts_at'] ?? null)
                        && ($exception['starts_at'] ?? null) === ($exception['ends_at'] ?? null)) {
                        $validator->errors()->add(
                            "exceptions.{$index}.ends_at",
                            __('groups.availability.validation.end_time'),
                        );
                    }
                }
            },
        ];
    }

    private function minuteOfDay(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }
}
