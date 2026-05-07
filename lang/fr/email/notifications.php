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
        'social_account' => [
            'linked' => [
                'title' => 'Compte connecté lié',
                'body' => 'Votre compte :provider a été lié à FullParty.',
            ],
            'unlinked' => [
                'title' => 'Compte connecté dissocié',
                'body' => 'Votre compte :provider a été dissocié de FullParty.',
            ],
        ],
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
    'characters' => [
        'added' => [
            'title' => 'Personnage ajouté',
            'body' => ':character (:world / :datacenter) a été ajouté à votre compte via :method.',
        ],
        'refreshed' => [
            'title' => 'Personnage actualisé',
            'body' => ':character (:world / :datacenter) a été actualisé avec les dernières données du profil.',
        ],
        'primary_changed' => [
            'title' => 'Personnage principal mis à jour',
            'body' => ':character (:world / :datacenter) est maintenant votre personnage principal.',
        ],
        'unclaimed' => [
            'title' => 'Personnage dissocié',
            'body' => ':character (:world / :datacenter) a été retiré de votre compte.',
        ],
    ],
    'system' => [
        'maintenance' => [
            'title' => ':headline',
            'body' => ':message',
            'body_with_schedule' => ":message\nScheduled for: :scheduled_for",
        ],
        'announcement' => [
            'title' => ':headline',
            'body' => ':message',
        ],
    ],
];
