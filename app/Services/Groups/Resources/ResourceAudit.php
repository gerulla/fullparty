<?php

namespace App\Services\Groups\Resources;

use App\Models\Group;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

class ResourceAudit
{
    public function __construct(private readonly AuditLogger $logger) {}

    public function record(Group $group, User $user, Model $subject, string $action): void
    {
        // Group audit readers must not receive restricted titles, bodies, or revision summaries.
        $this->logger->log('group.resources.'.$action, 'info', 'group', $group->id, 'audit_log.events.group.resources.'.$action, $user, $subject);
    }
}
