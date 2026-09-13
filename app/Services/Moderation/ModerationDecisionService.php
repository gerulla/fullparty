<?php

namespace App\Services\Moderation;

use App\Models\BozjaHolster;
use App\Models\ModerationAction;
use App\Models\ModerationCase;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Support\Facades\DB;

class ModerationDecisionService
{
    public function __construct(private readonly ReportTargetRegistry $targets, private readonly AccountBanService $bans, private readonly AuditLogger $audit, private readonly ContentModerationNoticeService $notices) {}

    public function act(ModerationCase $case, User $admin, array $data): void
    {
        DB::transaction(function () use ($case, $admin, $data) {
            $case = ModerationCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($case, $data['version']);
            $action = $data['action'];
            if ($action === 'claim') {
                $case->update(['assigned_to' => $admin->id, 'status' => $case->status === 'new' ? 'in_review' : $case->status]);
            } else {
                abort_unless($case->assigned_to === $admin->id, 409, __('reports.errors.claim_first'));
                abort_if($case->status === 'resolved' && ! in_array($action, ['restore', 'unban', 'reopen'], true), 422);
                $metadata = [];
                if ($action === 'reopen') {
                    abort_unless($case->status === 'resolved', 409, __('reports.errors.stale'));
                    // Match report submission's target lock before reclaiming the unique open case key.
                    $this->targets->adapter($case->target_type)->modelClass()::whereKey($case->target_id)->lockForUpdate()->first();
                    // Retain a shared lock anchor even if the original content has been deleted.
                    ModerationCase::where('target_type', $case->target_type)->where('target_id', $case->target_id)
                        ->orderBy('id')->lockForUpdate()->get(['id']);
                    $key = $case->target_type.':'.$case->target_id;
                    abort_if(ModerationCase::where('open_key', $key)->whereKeyNot($case->id)->exists(), 409, __('reports.errors.already_open'));
                    $case->update(['status' => 'in_review', 'resolved_at' => null, 'open_key' => $key]);
                } elseif (in_array($action, ['hide', 'restore'], true)) {
                    $adapter = $this->targets->adapter($case->target_type);
                    abort_unless($adapter->canHide(), 422);
                    $target = $adapter->modelClass()::whereKey($case->target_id)->lockForUpdate()->firstOrFail();
                    abort_if((bool) $target->moderation_hidden_at === ($action === 'hide'), 409, __('reports.errors.stale'));
                    $changes = ['moderation_hidden_at' => $action === 'hide' ? now() : null];
                    if ($target instanceof BozjaHolster) {
                        if ($action === 'hide') {
                            $metadata['was_active'] = $target->is_active;
                            $changes['is_active'] = false;
                        } else {
                            $previous = ModerationAction::query()
                                ->join('moderation_cases', 'moderation_cases.id', '=', 'moderation_actions.moderation_case_id')
                                ->where('moderation_cases.target_type', $case->target_type)
                                ->where('moderation_cases.target_id', $case->target_id)
                                ->where('moderation_actions.action', 'hide')->orderByDesc('moderation_actions.id')
                                ->first(['moderation_actions.*']);
                            $changes['is_active'] = (bool) ($previous?->metadata['was_active'] ?? false);
                        }
                    }
                    $target->forceFill($changes)->save();
                } elseif (in_array($action, ['ban', 'unban'], true)) {
                    $user = User::whereKey($case->subject_user_id)->lockForUpdate()->firstOrFail();
                    abort_if((bool) $user->banned_at === ($action === 'ban'), 409, __('reports.errors.stale'));
                    $this->bans->setBanned($user, $admin, $action === 'ban');
                    $metadata['user_id'] = $user->id;
                }
                if ($case->status !== 'resolved' && $action !== 'reopen') {
                    $case->update(['status' => 'awaiting_feedback']);
                }
            }
            $case->increment('version');
            $record = ModerationAction::create([
                'moderation_case_id' => $case->id, 'admin_id' => $admin->id, 'action' => $action,
                'reason' => $data['reason'] ?? null, 'metadata' => $metadata ?? null,
            ]);
            if (in_array($action, ['hide', 'restore'], true)) {
                $this->notices->send($case, $target, $record, $admin);
            }
            $this->audit->log(action: 'moderation.'.$action, severity: AuditSeverity::MODERATION_CHANGE,
                scopeType: AuditScope::ADMIN, scopeId: null, message: 'Moderation case #'.$case->id.': '.$action,
                actor: $admin, subject: $case, metadata: ['reason' => $data['reason'] ?? null]);
        }, 3);
    }

    public function assertVersion(ModerationCase $case, int $version): void
    {
        abort_unless($case->version === $version, 409, __('reports.errors.stale'));
    }
}
