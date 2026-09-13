<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContentReportRequest;
use App\Services\Moderation\ReportSubmissionService;
use App\Services\Moderation\ReportTargetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContentReportController extends Controller
{
    public function create(Request $request, string $type, int $id, ReportTargetRegistry $targets): Response
    {
        $adapter = $targets->adapter($type);
        $target = $adapter->modelClass()::findOrFail($id);
        abort_unless($adapter->canReport($target, $request->user()), 404);

        return Inertia::render('Reports/Create', ['target' => [
            'type' => $type, 'id' => $id, 'label' => $adapter->snapshot($target)['title'],
        ]]);
    }

    public function store(StoreContentReportRequest $request, ReportSubmissionService $reports): JsonResponse
    {
        $reports->submit($request->user(), $request->validated());

        // Never disclose case IDs, other reporters, or whether a case already existed.
        return response()->json(['message' => __('reports.thanks')], 201);
    }
}
