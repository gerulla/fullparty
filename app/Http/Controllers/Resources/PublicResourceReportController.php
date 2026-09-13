<?php

namespace App\Http\Controllers\Resources;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGuestReportRequest;
use App\Models\Group;
use App\Services\Moderation\GuestReportIdentity;
use App\Services\Moderation\ReportSubmissionService;
use Illuminate\Http\JsonResponse;

class PublicResourceReportController extends Controller
{
    public function store(StoreGuestReportRequest $request, Group $group, GuestReportIdentity $identity, ReportSubmissionService $reports): JsonResponse
    {
        $reports->submitGuest($group, $identity->fingerprint($request), $request->validated());

        return response()->json(['message' => __('reports.guest_thanks')], 201);
    }
}
