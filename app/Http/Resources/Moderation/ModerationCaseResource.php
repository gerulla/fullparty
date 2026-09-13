<?php

namespace App\Http\Resources\Moderation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ModerationCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'title' => $this->title, 'target_type' => $this->target_type,
            'target_id' => $this->target_id, 'status' => $this->status, 'version' => $this->version,
            'assigned_to' => $this->assigned_to, 'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee?->only(['id', 'name'])),
            'reports_count' => $this->whenCounted('reports'), 'created_at' => $this->created_at?->toIso8601String(),
            'reports' => $this->whenLoaded('reports', fn () => $this->reports->map(fn ($report) => [
                'id' => $report->id, 'reason' => $report->reason, 'details' => $report->details,
                'snapshot' => $report->snapshot, 'reporter' => $report->reporter ? (new ReportProfileResource($report->reporter))->resolve($request) : null,
                'guest_reference' => $report->guest_fingerprint ? substr($report->guest_fingerprint, 0, 12) : null,
                'created_at' => $report->created_at->toIso8601String(),
                'evidence_url' => $report->evidence_path ? route('admin.reports.evidence', $report, false) : null,
            ])),
            'actions' => $this->whenLoaded('actions', fn () => $this->actions->map(fn ($action) => [
                'id' => $action->id, 'action' => $action->action, 'reason' => $action->reason,
                'admin' => $action->admin?->name, 'created_at' => $action->created_at->toIso8601String(),
            ])),
            'feedback' => $this->whenLoaded('feedback', fn () => $this->feedback->map(fn ($feedback) => [
                'id' => $feedback->id, 'template' => $feedback->template, 'message' => $feedback->message,
                'audience' => $feedback->audience, 'item_title' => $feedback->item_title,
                'created_at' => $feedback->created_at->toIso8601String(),
                'recipient_count' => $feedback->recipients_count, 'acknowledged_count' => $feedback->acknowledged_count,
            ])),
        ];
    }
}
