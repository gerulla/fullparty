<?php

return [
    'runs' => [
        'cancelled' => [
            'title' => 'Run annulé',
            'body' => ':activity a été annulé.',
        ],
        'starting_soon' => [
            'title' => 'Run bientôt lancé',
            'body' => ':activity commence bientôt.',
        ],
        'starting_now' => [
            'title' => 'Run lancé maintenant',
            'body' => ':activity commence maintenant.',
        ],
        'completed' => [
            'title' => 'Run terminé',
            'body' => ':activity est maintenant terminé.',
        ],
    ],
    'assignments' => [
        'roster_published_assigned' => [
            'title' => 'Roster publié',
            'body' => 'Le roster de :activity a été publié. Vous êtes assigné à :slot en tant que :character.',
        ],
        'roster_published_bench' => [
            'title' => 'Roster publié',
            'body' => 'Le roster de :activity a été publié. Vous êtes sur le banc en tant que :character.',
        ],
        'assigned' => [
            'title' => 'Affectation du roster mise à jour',
            'body' => 'Vous êtes maintenant assigné à :slot pour :activity en tant que :character.',
        ],
        'on_bench' => [
            'title' => 'Affectation sur le banc mise à jour',
            'body' => 'Vous êtes maintenant sur le banc pour :activity en tant que :character.',
        ],
        'returned_to_queue' => [
            'title' => 'Affectation renvoyée en revue',
            'body' => 'Votre candidature pour :activity en tant que :character a été renvoyée dans la file de revue.',
        ],
        'designation_assigned' => [
            'title' => 'Rôle de run mis à jour',
            'body' => 'Tu as été marqué comme :designation pour :activity dans :slot.',
        ],
        'designation_removed' => [
            'title' => 'Rôle de run mis à jour',
            'body' => 'Tu n’es plus marqué comme :designation pour :activity.',
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
            'body_with_schedule' => ':message
Prévu pour : :scheduled_for',
        ],
        'announcement' => [
            'title' => ':headline',
            'body' => ':message',
        ],
    ],
];
