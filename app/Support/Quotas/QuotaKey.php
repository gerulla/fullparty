<?php

namespace App\Support\Quotas;

final class QuotaKey
{
    public const GROUPS_OWNED = 'groups.owned';

    public const GROUPS_JOINED = 'groups.joined';

    public const RUNS_PER_DAY = 'runs.per_day';

    public const FUTURE_RUNS = 'runs.future';

    public const CHARACTERS_TOTAL = 'characters.total';

    public const ACTIVE_APPLICATIONS = 'applications.active';

    public const PENDING_MEMBERSHIP_APPLICATIONS = 'membership_applications.pending';

    public const ACTIVE_INVITES = 'invites.active';

    public const HOLSTERS_TOTAL = 'holsters.total';

    public const PHANTOM_COMPOSITIONS_TOTAL = 'phantom_compositions.total';

    public const MEMBER_NOTES_PER_MEMBER = 'member_notes.per_member';

    public const MEMBER_NOTE_ADDENDA_PER_NOTE = 'member_note_addenda.per_note';

    public const RUN_LIST_GROUPS_TOTAL = 'run_list_groups.total';

    /** @var array<string, string> */
    public const SCOPES = [
        self::GROUPS_OWNED => QuotaScope::USER,
        self::GROUPS_JOINED => QuotaScope::USER,
        self::RUNS_PER_DAY => QuotaScope::GROUP,
        self::FUTURE_RUNS => QuotaScope::GROUP,
        self::CHARACTERS_TOTAL => QuotaScope::USER,
        self::ACTIVE_APPLICATIONS => QuotaScope::USER,
        self::PENDING_MEMBERSHIP_APPLICATIONS => QuotaScope::USER,
        self::ACTIVE_INVITES => QuotaScope::GROUP,
        self::HOLSTERS_TOTAL => QuotaScope::GROUP,
        self::PHANTOM_COMPOSITIONS_TOTAL => QuotaScope::GROUP,
        self::MEMBER_NOTES_PER_MEMBER => QuotaScope::GROUP,
        self::MEMBER_NOTE_ADDENDA_PER_NOTE => QuotaScope::GROUP,
        self::RUN_LIST_GROUPS_TOTAL => QuotaScope::USER,
    ];

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_keys(self::SCOPES);
    }

    /** @return array<int, string> */
    public static function forScope(string $scope): array
    {
        return array_keys(array_filter(
            self::SCOPES,
            fn (string $definedScope): bool => $definedScope === $scope,
        ));
    }

    public static function scope(string $key): ?string
    {
        return self::SCOPES[$key] ?? null;
    }
}
