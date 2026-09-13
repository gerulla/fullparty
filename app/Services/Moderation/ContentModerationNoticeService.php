<?php

namespace App\Services\Moderation;

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\ModerationAction;
use App\Models\ModerationCase;
use App\Models\ReportFeedback;
use App\Models\ReportFeedbackRecipient;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationTopic;
use Illuminate\Database\Eloquent\Model;

class ContentModerationNoticeService
{
    public function __construct(private readonly ReportTargetRegistry $targets, private readonly NotificationService $notifications) {}

    public function send(ModerationCase $case, Model $target, ModerationAction $action, User $admin): void
    {
        // Group ownership is a contact responsibility, not evidence of authorship for account sanctions.
        $ownerId = Group::whereKey($target->group_id)->value('owner_id');
        $authorId = ($target instanceof GroupResource ? $target->author_user_id : null)
            ?? $this->targets->adapter($case->target_type)->subjectUserId($target);
        $users = User::whereIn('id', array_filter([$ownerId, $authorId]))->get();
        if ($users->isEmpty()) {
            return;
        }

        $template = $action->action === 'hide' ? 'hidden' : 'restored';
        $notice = ReportFeedback::firstOrCreate(['moderation_action_id' => $action->id], [
            'moderation_case_id' => $case->id, 'admin_id' => $admin->id, 'audience' => 'owner',
            'template' => $template, 'item_title' => $case->title,
        ]);
        if (! $notice->wasRecentlyCreated) {
            return;
        }
        foreach ($users as $user) {
            ReportFeedbackRecipient::create(['report_feedback_id' => $notice->id, 'user_id' => $user->id]);
        }

        $event = $this->notifications->createEvent(
            type: 'moderation.content.'.$template, category: NotificationCategory::SYSTEM_NOTICES,
            titleKey: 'reports.owner_notice_title', bodyKey: 'reports.owner_notice.'.$template,
            messageParams: ['item' => $notice->item_title],
            actionUrl: route('account.notifications.index', [], false),
            actor: $admin, subject: $notice, isMandatory: true, topic: NotificationTopic::SYSTEM_ANNOUNCEMENTS,
        );
        $this->notifications->sendInAppNotifications($event, $users);
    }
}
