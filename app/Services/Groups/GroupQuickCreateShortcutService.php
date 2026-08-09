<?php

namespace App\Services\Groups;

use App\Models\Group;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Support\Facades\DB;

class GroupQuickCreateShortcutService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<int, array{time: string, time_mode: string}> $shortcuts */
    public function replace(Group $group, array $shortcuts, User $actor): void
    {
        $before = $group->resolvedQuickCreateShortcuts()
            ->map(fn ($shortcut): array => [
                'time' => $shortcut->time_of_day,
                'time_mode' => $shortcut->time_mode,
            ])
            ->values()
            ->all();

        $after = collect($shortcuts)
            ->map(fn (array $shortcut): array => [
                'time' => $shortcut['time'],
                'time_mode' => $shortcut['time_mode'],
            ])
            ->values()
            ->all();

        if ($before === $after) {
            return;
        }

        DB::transaction(function () use ($group, $after, $before, $actor): void {
            $group->quickCreateShortcuts()->delete();
            $group->quickCreateShortcuts()->createMany(
                collect($after)
                    ->map(fn (array $shortcut, int $index): array => [
                        'time_of_day' => $shortcut['time'],
                        'time_mode' => $shortcut['time_mode'],
                        'sort_order' => $index,
                    ])
                    ->all(),
            );

            $this->auditLogger->log(
                action: 'group.updated',
                severity: AuditSeverity::MODERATION_CHANGE,
                scopeType: AuditScope::GROUP,
                scopeId: $group->id,
                message: 'audit_log.events.group.updated',
                actor: $actor,
                subject: $group,
                metadata: [
                    'changed_fields' => ['quick_create_shortcuts'],
                    'changes' => [
                        'quick_create_shortcuts' => [
                            'before' => $before,
                            'after' => $after,
                        ],
                    ],
                ],
            );
        });
    }
}
