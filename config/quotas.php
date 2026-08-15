<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enforcement mode
    |--------------------------------------------------------------------------
    |
    | "observe" records would-be quota violations without blocking requests.
    | Switch production to "enforce" after reviewing the observation logs.
    |
    */
    'mode' => env('QUOTA_MODE', 'observe'),

    'limits' => [
        'groups.owned' => (int) env('QUOTA_GROUPS_OWNED', 5),
        'groups.joined' => (int) env('QUOTA_GROUPS_JOINED', 100),
        'runs.per_day' => (int) env('QUOTA_RUNS_PER_DAY', 5),
        'runs.future' => (int) env('QUOTA_FUTURE_RUNS', 100),
        'characters.total' => (int) env('QUOTA_CHARACTERS', 10),
        'applications.active' => (int) env('QUOTA_ACTIVE_APPLICATIONS', 100),
        'membership_applications.pending' => (int) env('QUOTA_PENDING_MEMBERSHIP_APPLICATIONS', 20),
        'invites.active' => (int) env('QUOTA_ACTIVE_INVITES', 20),
        'holsters.total' => (int) env('QUOTA_HOLSTERS', 50),
        'phantom_compositions.total' => (int) env('QUOTA_PHANTOM_COMPOSITIONS', 25),
        'member_notes.per_member' => (int) env('QUOTA_MEMBER_NOTES', 50),
        'member_note_addenda.per_note' => (int) env('QUOTA_MEMBER_NOTE_ADDENDA', 20),
        'run_list_groups.total' => (int) env('QUOTA_RUN_LIST_GROUPS', 100),
    ],
];
