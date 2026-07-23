<?php

return [
    'activities' => [
        'management' => [
            'messages' => [
                'application_cancelled_assignment' => 'This application was cancelled and has been removed from the queue.',
                'application_no_longer_pending_assignment' => 'This application is no longer pending and has been removed from the queue.',
                'missing_application_cancelled' => 'This player cancelled their registration, so the missing entry was removed.',
                'party_finder_archived' => 'Party Finder info cannot be published for a completed or cancelled run.',
            ],
            'queue' => [
                'modal' => [
                    'character_refresh_failed' => 'Character data could not be refreshed right now.',
                    'character_refresh_not_found' => 'This character could not be found on Lodestone.',
                    'character_refresh_unavailable' => 'This application does not have a character that can be refreshed.',
                    'character_refresh_cooldown' => 'This character was checked recently. Try again in a few minutes.',
                ],
            ],
        ],
    ],
    'membership_applications' => [
        'apply' => [
            'validation' => [
                'pending_exists' => 'You already have a pending request for this group.',
            ],
        ],
        'review' => [
            'validation' => [
                'already_reviewed' => 'This request has already been reviewed.',
                'applicant_banned' => 'Banned users cannot be approved into the group.',
            ],
        ],
        'form' => [
            'validation' => [
                'fields_required' => 'Provide at least one question.',
                'minimum_fields' => 'Application forms need at least one question.',
                'max_fields' => 'Application forms can have up to :max questions.',
                'field_invalid' => 'Each question must be a valid object.',
                'type_invalid' => 'Choose a valid input type.',
                'name_required' => 'The English name is required.',
                'localized_text_invalid' => 'Localized text must be text.',
                'localized_text_max' => 'Localized text must be :max characters or fewer.',
                'options_required' => 'Select menu questions need at least one option.',
                'options_max' => 'Select menu questions can have up to :max options.',
                'option_invalid' => 'Each option must be a valid object.',
                'answer_unknown' => 'This answer does not match the current application form.',
                'answer_required' => 'This answer is required.',
                'answer_invalid' => 'Provide a valid answer.',
                'answer_max' => 'This answer must be :max characters or fewer.',
                'answer_option_invalid' => 'Choose one of the available options.',
            ],
        ],
    ],
];
