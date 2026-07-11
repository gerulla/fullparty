<?php

return [
    'runs' => [
        'cancelled' => [
            'title' => 'Run abgesagt',
            'body' => ':activity wurde abgesagt.',
        ],
        'starting_soon' => [
            'title' => 'Run startet bald',
            'body' => ':activity startet bald.',
        ],
        'starting_now' => [
            'title' => 'Run startet jetzt',
            'body' => ':activity startet jetzt.',
        ],
        'party_finder_published' => [
            'title' => 'Party-Finder-Info verfügbar',
            'body' => ':character auf :world hat den Party Finder geöffnet. Passwort: :password',
        ],
        'completed' => [
            'title' => 'Run abgeschlossen',
            'body' => ':activity ist jetzt abgeschlossen.',
        ],
    ],
    'assignments' => [
        'roster_published_assigned' => [
            'title' => 'Roster veröffentlicht',
            'body' => 'Das Roster für :activity wurde veröffentlicht. Du bist als :character auf :slot eingeteilt.',
        ],
        'roster_published_bench' => [
            'title' => 'Roster veröffentlicht',
            'body' => 'Das Roster für :activity wurde veröffentlicht. Du bist mit :character auf der Ersatzbank.',
        ],
        'assigned' => [
            'title' => 'Roster-Zuteilung aktualisiert',
            'body' => 'Du bist jetzt für :activity als :character auf :slot eingeteilt.',
        ],
        'on_bench' => [
            'title' => 'Ersatzbank-Zuteilung aktualisiert',
            'body' => 'Du bist jetzt für :activity mit :character auf der Ersatzbank.',
        ],
        'returned_to_queue' => [
            'title' => 'Zuteilung wieder in Prüfung',
            'body' => 'Deine Bewerbung für :activity als :character wurde zurück in die Prüfung verschoben.',
        ],
        'designation_assigned' => [
            'title' => 'Run-Rolle aktualisiert',
            'body' => 'Du wurdest für :activity in :slot als :designation markiert.',
        ],
        'designation_removed' => [
            'title' => 'Run-Rolle aktualisiert',
            'body' => 'Du bist für :activity nicht mehr als :designation markiert.',
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
    'system' => [
        'maintenance' => [
            'title' => ':headline',
            'body' => ':message',
            'body_with_schedule' => ':message
Geplant für: :scheduled_for',
        ],
        'announcement' => [
            'title' => ':headline',
            'body' => ':message',
        ],
    ],
];
