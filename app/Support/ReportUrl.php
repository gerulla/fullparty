<?php

namespace App\Support;

final class ReportUrl
{
    public static function for(string $type, int $id): string
    {
        // Public resource pages deliberately do not share the authenticated host.
        return rtrim(config('app.url'), '/').route('reports.create', [
            'locale' => app()->getLocale(), 'type' => $type, 'id' => $id,
        ], false);
    }
}
