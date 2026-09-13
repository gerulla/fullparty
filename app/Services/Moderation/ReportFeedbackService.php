<?php

namespace App\Services\Moderation;

use App\Models\ModerationCase;
use App\Models\ReportFeedback;
use App\Models\ReportFeedbackRecipient;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationTopic;
use Illuminate\Support\Facades\DB;

class ReportFeedbackService
{
    public const TEMPLATES = ['hidden', 'banned', 'action_taken', 'no_violation', 'already_unavailable', 'other'];

    public function __construct(private readonly NotificationService $notifications, private readonly ModerationDecisionService $decisions, private readonly AuditLogger $audit, private readonly ReportTargetRegistry $targets) {}

    public function send(ModerationCase $case, User $admin, array $data): void
    {
        DB::transaction(function () use ($case, $admin, $data) {
            $case = ModerationCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->decisions->assertVersion($case, $data['version']);
            abort_unless($case->status === 'awaiting_feedback' && $case->assigned_to === $admin->id, 409, __('reports.errors.claim_first'));
            $actions = $case->actions()->orderBy('id')->pluck('action')->all();
            $lastContentAction = collect($actions)->filter(fn ($action) => in_array($action, ['hide', 'restore']))->last();
            $lastAccountAction = collect($actions)->filter(fn ($action) => in_array($action, ['ban', 'unban']))->last();
            $target = $this->targets->adapter($case->target_type)->modelClass()::find($case->target_id);
            $hidden = $lastContentAction === 'hide' && (bool) $target?->moderation_hidden_at;
            $banned = $lastAccountAction === 'ban' && (bool) $case->subjectUser?->banned_at;
            $template = $data['template'];
            $valid = match ($template) {
                'hidden' => $hidden,
                'banned' => $banned,
                'action_taken' => $hidden || $banned,
                'no_violation' => end($actions) === 'dismiss' && ! $hidden && ! $banned,
                'already_unavailable' => end($actions) === 'dismiss' && ! $target,
                default => true,
            };
            abort_unless($valid, 422, __('reports.errors.feedback_mismatch'));
            $feedback = ReportFeedback::create([
                'moderation_case_id' => $case->id, 'admin_id' => $admin->id, 'template' => $template,
                'message' => $template === 'other' ? trim($data['message']) : null, 'item_title' => $case->title,
            ]);
            $users = User::whereIn('id', $case->reports()->whereNotNull('reporter_id')->select('reporter_id'))->get();
            foreach ($users as $user) {
                ReportFeedbackRecipient::create(['report_feedback_id' => $feedback->id, 'user_id' => $user->id]);
            }
            $event = $this->notifications->createEvent(
                type: 'reports.feedback', category: NotificationCategory::SYSTEM_NOTICES,
                titleKey: 'reports.feedback_title', bodyKey: 'reports.feedback.'.$template,
                messageParams: ['item' => $case->title, 'message' => $feedback->message ?? ''],
                actionUrl: route('account.notifications.index', [], false),
                actor: $admin, subject: $feedback, isMandatory: true, topic: NotificationTopic::SYSTEM_ANNOUNCEMENTS,
            );
            $this->notifications->sendInAppNotifications($event, $users);
            $case->update(['status' => 'resolved', 'resolved_at' => now(), 'open_key' => null, 'version' => $case->version + 1]);
            $this->audit->log(action: 'moderation.feedback_sent', severity: AuditSeverity::MODERATION_CHANGE,
                scopeType: AuditScope::ADMIN, scopeId: null, message: 'Moderation feedback sent for case #'.$case->id,
                actor: $admin, subject: $case, metadata: ['template' => $template, 'recipient_count' => $users->count()]);
        }, 3);
    }

    public function acknowledge(User $user, ReportFeedbackRecipient $recipient): void
    {
        abort_unless($recipient->user_id === $user->id, 404);
        ReportFeedbackRecipient::whereKey($recipient->id)->where('user_id', $user->id)
            ->whereNull('acknowledged_at')->update(['acknowledged_at' => now()]);
    }
}
