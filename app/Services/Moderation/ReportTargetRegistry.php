<?php

namespace App\Services\Moderation;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\Group;
use App\Models\GroupMembershipApplication;
use App\Models\GroupUserNote;
use App\Models\User;
use App\Services\Moderation\Targets\ContextReportTarget;
use App\Services\Moderation\Targets\HolsterReportTarget;
use App\Services\Moderation\Targets\PrivateContentReportTarget;
use App\Services\Moderation\Targets\ResourceReportTarget;
use App\Services\Moderation\Targets\UploadReportTarget;

class ReportTargetRegistry
{
    public const TYPES = ['resource', 'upload', 'holster', 'group', 'run', 'profile', 'application', 'membership_application', 'member_note'];

    public function adapter(string $type): ReportTarget
    {
        return match ($type) {
            'resource' => app(ResourceReportTarget::class),
            'upload' => app(UploadReportTarget::class),
            'holster' => app(HolsterReportTarget::class),
            'group' => new ContextReportTarget(Group::class),
            'run' => new ContextReportTarget(Activity::class),
            'profile' => new ContextReportTarget(User::class),
            'application' => new PrivateContentReportTarget(ActivityApplication::class),
            'membership_application' => new PrivateContentReportTarget(GroupMembershipApplication::class),
            'member_note' => new PrivateContentReportTarget(GroupUserNote::class),
            default => abort(404),
        };
    }
}
