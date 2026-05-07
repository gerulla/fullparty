<?php

return [
    'assignments' => [
        'roster_published_assigned' => [
            'title' => 'Roster publie',
            'body' => 'Le roster de :activity a ete publie. Vous etes assigne a :slot en tant que :character.',
        ],
        'roster_published_bench' => [
            'title' => 'Roster publie',
            'body' => 'Le roster de :activity a ete publie. Vous etes sur le banc en tant que :character.',
        ],
        'assigned' => [
            'title' => 'Affectation du roster mise a jour',
            'body' => 'Vous etes maintenant assigne a :slot pour :activity en tant que :character.',
        ],
        'on_bench' => [
            'title' => 'Affectation sur le banc mise a jour',
            'body' => 'Vous etes maintenant sur le banc pour :activity en tant que :character.',
        ],
        'returned_to_queue' => [
            'title' => 'Affectation retournee en revue',
            'body' => 'Votre candidature pour :activity en tant que :character a ete renvoyee dans la file de revue.',
        ],
    ],
    'user' => [
        'settings' => [
            'notifications_updated' => [
                'title' => 'Préférences de notification mises à jour',
                'body' => 'Vos préférences de notification ont été mises à jour. Préférences modifiées : :settings',
            ],
            'username_updated' => [
                'title' => 'Paramètres du compte mis à jour',
                'body' => 'Les paramètres de votre compte ont été mis à jour. Paramètres modifiés : :settings',
            ],
            'privacy_updated' => [
                'title' => 'Paramètres de confidentialité mis à jour',
                'body' => 'Vos paramètres de confidentialité ont été mis à jour. Paramètres modifiés : :settings',
            ],
        ],
    ],
];
