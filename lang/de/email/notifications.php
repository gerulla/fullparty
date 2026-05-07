<?php

return [
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
];
