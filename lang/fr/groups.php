<?php

return [
    'activities' => [
        'management' => [
            'messages' => [
                'application_cancelled_assignment' => 'Cette candidature a été annulée et retirée de la file.',
                'application_no_longer_pending_assignment' => 'Cette candidature n\'est plus en attente et a été retirée de la file.',
                'missing_application_cancelled' => 'Ce joueur a annulé son inscription, l\'entrée manquante a donc été retirée.',
                'party_finder_archived' => 'Les informations de l\'outil de mission ne peuvent pas être publiées pour un run terminé ou annulé.',
            ],
            'queue' => [
                'modal' => [
                    'character_refresh_failed' => 'Les données du personnage n\'ont pas pu être actualisées pour le moment.',
                    'character_refresh_not_found' => 'Ce personnage est introuvable sur Lodestone.',
                    'character_refresh_unavailable' => 'Cette candidature n\'a aucun personnage à actualiser.',
                    'character_refresh_cooldown' => 'Ce personnage a été vérifié récemment. Réessayez dans quelques minutes.',
                ],
            ],
        ],
    ],
    'membership_applications' => [
        'apply' => [
            'validation' => [
                'pending_exists' => 'Vous avez déjà une demande en attente pour ce groupe.',
            ],
        ],
        'review' => [
            'validation' => [
                'already_reviewed' => 'Cette demande a déjà été traitée.',
                'applicant_banned' => 'Les utilisateurs bannis ne peuvent pas être acceptés dans le groupe.',
            ],
        ],
        'form' => [
            'validation' => [
                'fields_required' => 'Ajoutez au moins une question.',
                'minimum_fields' => 'Les formulaires de demande doivent avoir au moins une question.',
                'max_fields' => 'Les formulaires de demande peuvent avoir jusqu\'à :max questions.',
                'field_invalid' => 'Chaque question doit être un objet valide.',
                'type_invalid' => 'Choisissez un type de champ valide.',
                'name_required' => 'Le nom anglais est obligatoire.',
                'localized_text_invalid' => 'Le texte localisé doit être du texte.',
                'localized_text_max' => 'Le texte localisé doit contenir :max caractères ou moins.',
                'options_required' => 'Les questions à sélection doivent avoir au moins une option.',
                'options_max' => 'Les questions à sélection peuvent avoir jusqu\'à :max options.',
                'option_invalid' => 'Chaque option doit être un objet valide.',
                'answer_unknown' => 'Cette réponse ne correspond pas au formulaire actuel.',
                'answer_required' => 'Cette réponse est obligatoire.',
                'answer_invalid' => 'Saisissez une réponse valide.',
                'answer_max' => 'Cette réponse doit contenir :max caractères ou moins.',
                'answer_option_invalid' => 'Choisissez une des options disponibles.',
            ],
        ],
    ],
];
