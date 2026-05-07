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
            'title' => 'Roster veroffentlicht',
            'body' => 'Das Roster fur :activity wurde veroffentlicht. Du bist als :character auf :slot eingeteilt.',
        ],
        'roster_published_bench' => [
            'title' => 'Roster veroffentlicht',
            'body' => 'Das Roster fur :activity wurde veroffentlicht. Du bist mit :character auf der Bank.',
        ],
        'assigned' => [
            'title' => 'Roster-Zuteilung aktualisiert',
            'body' => 'Du bist jetzt fur :activity als :character auf :slot eingeteilt.',
        ],
        'on_bench' => [
            'title' => 'Bank-Zuteilung aktualisiert',
            'body' => 'Du bist jetzt fur :activity mit :character auf der Bank.',
        ],
        'returned_to_queue' => [
            'title' => 'Zuteilung wieder in Prufung',
            'body' => 'Deine Bewerbung fur :activity als :character wurde zuruck in die Prufung verschoben.',
        ],
    ],
    'user' => [
        'social_account' => [
            'linked' => [
                'title' => 'Verknüpftes Konto hinzugefügt',
                'body' => 'Dein :provider-Konto wurde mit FullParty verknüpft.',
            ],
            'unlinked' => [
                'title' => 'Verknüpftes Konto getrennt',
                'body' => 'Dein :provider-Konto wurde von FullParty getrennt.',
            ],
        ],
        'settings' => [
            'notifications_updated' => [
                'title' => 'Benachrichtigungseinstellungen aktualisiert',
                'body' => 'Deine Benachrichtigungseinstellungen wurden aktualisiert. Geänderte Präferenzen: :settings',
            ],
            'username_updated' => [
                'title' => 'Kontoeinstellungen aktualisiert',
                'body' => 'Deine Kontoeinstellungen wurden aktualisiert. Geänderte Einstellungen: :settings',
            ],
            'privacy_updated' => [
                'title' => 'Datenschutzeinstellungen aktualisiert',
                'body' => 'Deine Datenschutzeinstellungen wurden aktualisiert. Geänderte Einstellungen: :settings',
            ],
        ],
    ],
    'characters' => [
        'added' => [
            'title' => 'Charakter hinzugefügt',
            'body' => ':character (:world / :datacenter) wurde über :method zu deinem Konto hinzugefügt.',
        ],
        'refreshed' => [
            'title' => 'Charakter aktualisiert',
            'body' => ':character (:world / :datacenter) wurde mit den neuesten Profildaten aktualisiert.',
        ],
        'primary_changed' => [
            'title' => 'Primärcharakter aktualisiert',
            'body' => ':character (:world / :datacenter) ist jetzt dein Primärcharakter.',
        ],
        'unclaimed' => [
            'title' => 'Charakter freigegeben',
            'body' => ':character (:world / :datacenter) wurde aus deinem Konto entfernt.',
        ],
    ],
];
