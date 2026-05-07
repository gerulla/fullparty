<?php

return [
    'runs' => [
        'cancelled' => [
            'title' => 'Run cancelled',
            'body' => ':activity was cancelled.',
        ],
        'starting_soon' => [
            'title' => 'Run starting soon',
            'body' => ':activity is starting soon.',
        ],
        'starting_now' => [
            'title' => 'Run starting now',
            'body' => ':activity is starting now.',
        ],
        'completed' => [
            'title' => 'Run complete',
            'body' => ':activity is now complete.',
        ],
    ],
    'assignments' => [
        'roster_published_assigned' => [
            'title' => 'Roster published',
            'body' => 'The roster for :activity has been published. You are assigned to :slot as :character.',
        ],
        'roster_published_bench' => [
            'title' => 'Roster published',
            'body' => 'The roster for :activity has been published. You are on the bench as :character.',
        ],
        'assigned' => [
            'title' => 'Roster assignment updated',
            'body' => 'You are now assigned to :slot for :activity as :character.',
        ],
        'on_bench' => [
            'title' => 'Bench assignment updated',
            'body' => 'You are now on the bench for :activity as :character.',
        ],
        'returned_to_queue' => [
            'title' => 'Assignment returned to review',
            'body' => 'Your application for :activity as :character has been returned to the review queue.',
        ],
    ],
    'user' => [
        'social_account' => [
            'linked' => [
                'title' => 'Connected account linked',
                'body' => 'Your :provider account was linked to FullParty.',
            ],
            'unlinked' => [
                'title' => 'Connected account unlinked',
                'body' => 'Your :provider account was unlinked from FullParty.',
            ],
        ],
        'settings' => [
            'notifications_updated' => [
                'title' => 'Notification settings updated',
                'body' => 'Your notification preferences were updated. Changed preferences: :settings',
            ],
            'username_updated' => [
                'title' => 'Account settings updated',
                'body' => 'Your account settings were updated. Changed settings: :settings',
            ],
            'privacy_updated' => [
                'title' => 'Privacy settings updated',
                'body' => 'Your privacy settings were updated. Changed settings: :settings',
            ],
        ],
    ],
    'characters' => [
        'added' => [
            'title' => 'Character added',
            'body' => ':character (:world / :datacenter) was added to your account via :method.',
        ],
        'refreshed' => [
            'title' => 'Character refreshed',
            'body' => ':character (:world / :datacenter) was refreshed with the latest profile data.',
        ],
        'primary_changed' => [
            'title' => 'Primary character updated',
            'body' => ':character (:world / :datacenter) is now your primary character.',
        ],
        'unclaimed' => [
            'title' => 'Character unclaimed',
            'body' => ':character (:world / :datacenter) was removed from your account.',
        ],
    ],
];
