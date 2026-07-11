<?php

namespace App\Http\Resources\Groups;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupAvailabilityScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cycle_weeks' => $this->cycle_weeks,
            'repeats' => $this->repeats,
            'lock_weekends' => $this->lock_weekends,
            'on_hiatus' => $this->on_hiatus,
            'starts_on' => $this->starts_on?->toDateString(),
            'timezone' => $this->timezone,
            'windows' => $this->windows
                ->sortBy(fn ($window) => sprintf(
                    '%02d:%02d:%04d',
                    $window->cycle_week,
                    $window->weekday,
                    $window->starts_minute,
                ))
                ->values()
                ->map(fn ($window) => [
                    'cycle_week' => $window->cycle_week,
                    'weekday' => $window->weekday,
                    'status' => $window->status,
                    'starts_at' => $this->formatMinute($window->starts_minute),
                    'ends_at' => $this->formatMinute($window->ends_minute),
                ])
                ->all(),
            'exceptions' => $this->exceptions
                ->where('date', '>=', today())
                ->sortBy('date')
                ->values()
                ->map(fn ($exception) => [
                    'date' => $exception->date?->toDateString(),
                    'starts_at' => $exception->starts_minute !== null
                        ? $this->formatMinute($exception->starts_minute)
                        : null,
                    'ends_at' => $exception->ends_minute !== null
                        ? $this->formatMinute($exception->ends_minute)
                        : null,
                ])
                ->all(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function formatMinute(int $minute): string
    {
        $minute %= 1440;

        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }
}
