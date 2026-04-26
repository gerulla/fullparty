<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Group;
use App\Services\Groups\ActivityRosterCsvExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GroupActivityRosterExportController extends Controller
{
    public function show(
        Group $group,
        Activity $activity,
        ActivityRosterCsvExportService $exportService,
    ): StreamedResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        $payload = $exportService->build($activity);

        return response()->streamDownload(function () use ($payload) {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                return;
            }

            fwrite($stream, "\xEF\xBB\xBF");

            foreach ($payload['rows'] as $row) {
                fputcsv($stream, $row);
            }

            fclose($stream);
        }, $exportService->filename($activity), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
