<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\Group;
use Carbon\CarbonInterface;

final class ActivityCalendarExportService
{
    public function build(Group $group, Activity $activity, string $overviewUrl): string
    {
        $activity->loadMissing(['activityType', 'activityTypeVersion']);

        $startsAt = $activity->starts_at->copy()->utc();
        $endsAt = $startsAt->copy()->addMinutes($this->durationMinutes($activity));
        $title = filled($activity->title)
            ? trim((string) $activity->title)
            : $this->localizedText(
                $activity->activityTypeVersion?->name
                    ?? $activity->activityType?->draft_name,
                __('calendar.run_fallback'),
            );
        $progressPoint = collect($activity->activityTypeVersion?->prog_points ?? [])
            ->firstWhere('key', $activity->target_prog_point_key);
        $progressLabel = is_array($progressPoint)
            ? $this->localizedText($progressPoint['label'] ?? null)
            : null;
        $description = collect([
            __('calendar.group').': '.$group->name,
            $progressLabel ? __('calendar.progress_point').': '.$progressLabel : null,
            filled($activity->notes) ? trim((string) $activity->notes) : null,
            __('calendar.view_run').': '.$overviewUrl,
        ])->filter()->implode("\n");

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//FullParty//Run Calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:fullparty-activity-'.$activity->id.'@'.parse_url(config('app.url'), PHP_URL_HOST),
            'DTSTAMP:'.$this->formatDate($activity->updated_at ?? now()),
            'DTSTART:'.$this->formatDate($startsAt),
            'DTEND:'.$this->formatDate($endsAt),
            'SUMMARY:'.$this->escape($title),
            'DESCRIPTION:'.$this->escape($description),
            'URL:'.$this->escape($overviewUrl),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map($this->foldLine(...), $lines))."\r\n";
    }

    private function durationMinutes(Activity $activity): int
    {
        return max(1, (int) round(((float) ($activity->duration_hours ?: 1)) * 60));
    }

    private function formatDate(CarbonInterface $date): string
    {
        return $date->utc()->format('Ymd\THis\Z');
    }

    private function localizedText(mixed $value, ?string $fallback = null): ?string
    {
        if (is_string($value) && filled($value)) {
            return trim($value);
        }

        if (! is_array($value)) {
            return $fallback;
        }

        foreach ([app()->getLocale(), config('app.fallback_locale', 'en')] as $locale) {
            $candidate = $value[$locale] ?? null;

            if (is_string($candidate) && filled($candidate)) {
                return trim($candidate);
            }
        }

        $candidate = collect($value)->first(fn ($item) => is_string($item) && filled($item));

        return is_string($candidate) ? trim($candidate) : $fallback;
    }

    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", ';', ','],
            ['\\\\', '\\n', '\\n', '\\;', '\\,'],
            $value,
        );
    }

    private function foldLine(string $line): string
    {
        $folded = '';
        $limit = 75;

        while (strlen($line) > $limit) {
            $length = $limit;

            while ($length > 0 && (ord($line[$length]) & 0xC0) === 0x80) {
                $length--;
            }

            $folded .= substr($line, 0, $length)."\r\n ";
            $line = substr($line, $length);
            $limit = 74;
        }

        return $folded.$line;
    }
}
