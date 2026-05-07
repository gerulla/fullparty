<?php

namespace App\Http\Controllers;

use App\Models\SystemNotificationBroadcast;
use App\Services\AuditLogger;
use App\Services\Notifications\SystemNotificationService;
use App\Services\SystemBannerService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class SystemNotificationController extends Controller
{
    public function __construct(
        private readonly SystemNotificationService $systemNotificationService,
        private readonly SystemBannerService $systemBannerService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(): Response
    {
        $this->authorizeAdminAccess();

        $broadcasts = SystemNotificationBroadcast::query()
            ->with(['notificationEvent' => fn ($query) => $query
                ->with('actor:id,name')
                ->withCount('deliveries'),
            ])
            ->withCount('reads')
            ->latest('created_at')
            ->limit(50)
            ->get();

        return Inertia::render('Admin/SystemNotifications', [
            'currentBanner' => $this->systemBannerService->serialize(),
            'history' => $broadcasts->map(function (SystemNotificationBroadcast $broadcast) {
                $event = $broadcast->notificationEvent;

                return [
                'id' => sprintf('broadcast:%d', $broadcast->id),
                'type' => $event?->type,
                'is_mandatory' => (bool) $event?->is_mandatory,
                'title_key' => $event?->title_key,
                'body_key' => $event?->body_key,
                'message_params' => $event?->message_params,
                'action_url' => $event?->action_url,
                'payload' => $event?->payload,
                'created_at' => $broadcast->created_at?->toIso8601String(),
                'actor' => [
                    'id' => $event?->actor?->id,
                    'name' => $event?->actor?->name ?? 'System',
                ],
                'read_count' => (int) $broadcast->reads_count,
                'delivery_count' => (int) ($event?->deliveries_count ?? 0),
            ];
            }),
        ]);
    }

    public function storeMaintenance(): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $validated = request()->validate([
            'headline' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:2000'],
            'scheduled_for' => ['nullable', 'date'],
            'action_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $actor = request()->user();

        $broadcast = $this->systemNotificationService->sendUpcomingMaintenance(
            actor: $actor,
            headline: $validated['headline'],
            message: $validated['message'],
            scheduledFor: filled($validated['scheduled_for'] ?? null) ? Carbon::parse($validated['scheduled_for']) : null,
            actionUrl: $validated['action_url'] ?? null,
        );

        $this->auditLogger->log(
            action: 'admin.system_notification.maintenance_sent',
            severity: AuditSeverity::INFO,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.system_notification.maintenance_sent',
            actor: $actor,
            subject: null,
            metadata: [
                'headline' => $validated['headline'],
                'scheduled_for' => $validated['scheduled_for'] ?? null,
                'notification_event_id' => $broadcast->notification_event_id,
                'broadcast_id' => $broadcast->id,
            ],
        );

        return back()->with('success', 'system_notification_maintenance_sent');
    }

    public function storeAnnouncement(): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $validated = request()->validate([
            'headline' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:2000'],
            'action_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $actor = request()->user();

        $broadcast = $this->systemNotificationService->sendAnnouncement(
            actor: $actor,
            headline: $validated['headline'],
            message: $validated['message'],
            actionUrl: $validated['action_url'] ?? null,
        );

        $this->auditLogger->log(
            action: 'admin.system_notification.announcement_sent',
            severity: AuditSeverity::INFO,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.system_notification.announcement_sent',
            actor: $actor,
            subject: null,
            metadata: [
                'headline' => $validated['headline'],
                'notification_event_id' => $broadcast->notification_event_id,
                'broadcast_id' => $broadcast->id,
            ],
        );

        return back()->with('success', 'system_notification_announcement_sent');
    }

    public function storeBanner(): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $validated = request()->validate([
            'title' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:2000'],
            'action_label' => ['nullable', 'string', 'max:40', 'required_with:action_url'],
            'action_url' => ['nullable', 'url', 'max:2048', 'required_with:action_label'],
        ]);

        $actor = request()->user();

        $banner = $this->systemBannerService->upsert([
            'title' => $validated['title'],
            'message' => $validated['message'],
            'action_label' => $validated['action_label'] ?? null,
            'action_url' => $validated['action_url'] ?? null,
        ]);

        $this->auditLogger->log(
            action: 'admin.system_banner.updated',
            severity: AuditSeverity::INFO,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.system_banner.updated',
            actor: $actor,
            subject: $banner,
            metadata: [
                'title' => $banner->title,
                'action_label' => $banner->action_label,
                'action_url' => $banner->action_url,
            ],
        );

        return back()->with('success', 'system_banner_saved');
    }

    public function clearBanner(): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $actor = request()->user();
        $banner = $this->systemBannerService->current();

        $this->systemBannerService->clear();

        $this->auditLogger->log(
            action: 'admin.system_banner.cleared',
            severity: AuditSeverity::INFO,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.system_banner.cleared',
            actor: $actor,
            subject: $banner,
            metadata: [
                'title' => $banner?->title,
            ],
        );

        return back()->with('success', 'system_banner_cleared');
    }

    private function authorizeAdminAccess(): void
    {
        if (!auth()->user()?->is_admin) {
            abort(403);
        }
    }
}
