<?php

namespace App\Http\Controllers;

use App\Http\Resources\Moderation\ReportFeedbackResource;
use App\Models\ReportFeedbackRecipient;
use App\Services\Moderation\ReportFeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportFeedbackController extends Controller
{
    public function pending(Request $request): JsonResponse
    {
        $pending = ReportFeedbackRecipient::where('user_id', $request->user()->id)->whereNull('acknowledged_at');
        $next = (clone $pending)->with('feedback')->orderBy('id')->first();

        return response()->json(['next' => $next ? new ReportFeedbackResource($next) : null, 'count' => $pending->count()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function acknowledge(Request $request, ReportFeedbackRecipient $recipient, ReportFeedbackService $feedback): JsonResponse
    {
        $feedback->acknowledge($request->user(), $recipient);

        return $this->pending($request);
    }
}
