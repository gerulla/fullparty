<?php

namespace App\Http\Controllers;

use App\Http\Requests\ModerationActionRequest;
use App\Http\Requests\SendReportFeedbackRequest;
use App\Http\Resources\Moderation\ModerationCaseResource;
use App\Models\ContentReport;
use App\Models\GroupResourceImage;
use App\Models\ModerationCase;
use App\Services\Moderation\ModerationDecisionService;
use App\Services\Moderation\ReportCasePresenter;
use App\Services\Moderation\ReportFeedbackService;
use App\Services\Moderation\ReportTargetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminReportController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(ModerationCase::STATUSES)],
            'type' => ['nullable', Rule::in(ReportTargetRegistry::TYPES)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $cases = ModerationCase::query()->with('assignee:id,name')->withCount('reports')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('target_type', $type))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->whereLike('title', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $text).'%'))
            ->orderByDesc('updated_at')->paginate(20)->withQueryString();

        return Inertia::render('Admin/Reports', [
            'cases' => ModerationCaseResource::collection($cases), 'filters' => $filters,
            'counts' => ModerationCase::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'targetTypes' => ReportTargetRegistry::TYPES,
        ]);
    }

    public function show(Request $request, ModerationCase $case, ReportCasePresenter $presenter): HttpResponse
    {
        $detail = $presenter->detail($case);
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($detail)->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('Admin/Reports/Show', ['detail' => $detail])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function asset(ModerationCase $case, GroupResourceImage $image, ReportCasePresenter $presenter): StreamedResponse
    {
        abort_unless($presenter->images($case)->contains('id', $image->id), 404);
        $disk = Storage::disk(config('group_resources.disk'));
        abort_unless($disk->exists($image->path), 404);

        return $disk->response($image->path, $image->original_name, [
            'Content-Type' => $image->mime_type, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function action(ModerationActionRequest $request, ModerationCase $case, ModerationDecisionService $decisions, ReportCasePresenter $presenter): JsonResponse
    {
        $decisions->act($case, $request->user(), $request->validated());

        return response()->json($presenter->detail($case->fresh()))->header('Cache-Control', 'private, no-store');
    }

    public function feedback(SendReportFeedbackRequest $request, ModerationCase $case, ReportFeedbackService $feedback, ReportCasePresenter $presenter): JsonResponse
    {
        $feedback->send($case, $request->user(), $request->validated());

        return response()->json($presenter->detail($case->fresh()))->header('Cache-Control', 'private, no-store');
    }

    public function evidence(ContentReport $report): StreamedResponse
    {
        abort_unless($report->evidence_path && Storage::disk('local')->exists($report->evidence_path), 404);

        return Storage::disk('local')->response($report->evidence_path, 'evidence', [
            'Content-Type' => $report->snapshot['content']['mime_type'] ?? 'application/octet-stream',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
