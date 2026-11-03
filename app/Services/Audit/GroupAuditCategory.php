<?php

namespace App\Services\Audit;

use Illuminate\Database\Eloquent\Builder;

final class GroupAuditCategory
{
    private const PREFIXES = [
        'runs' => ['group.activity.'],
        'members' => ['group.member.', 'group.membership_application.', 'group.personal_invite.', 'group.invite.', 'group.followed', 'group.unfollowed'],
        'resources' => ['group.resources.'],
    ];

    public static function forAction(string $action): string
    {
        foreach (self::PREFIXES as $category => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($action, $prefix)) {
                    return $category;
                }
            }
        }

        return 'settings';
    }

    public static function apply(Builder $query, string $category): void
    {
        if ($category === 'settings') {
            foreach (self::PREFIXES as $prefixes) {
                foreach ($prefixes as $prefix) {
                    $query->where('action', 'not like', $prefix.'%');
                }
            }
        } elseif (isset(self::PREFIXES[$category])) {
            $query->where(function (Builder $query) use ($category) {
                foreach (self::PREFIXES[$category] as $prefix) {
                    $query->orWhere('action', 'like', $prefix.'%');
                }
            });
        }
    }
}
