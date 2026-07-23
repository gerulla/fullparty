<?php

return [
    'activities' => [
        'management' => [
            'messages' => [
                'application_cancelled_assignment' => 'Diese Bewerbung wurde abgebrochen und aus der Warteschlange entfernt.',
                'application_no_longer_pending_assignment' => 'Diese Bewerbung ist nicht mehr ausstehend und wurde aus der Warteschlange entfernt.',
                'missing_application_cancelled' => 'Dieser Spieler hat seine Anmeldung abgebrochen, daher wurde der Fehlend-Eintrag entfernt.',
                'party_finder_archived' => 'Für einen abgeschlossenen oder abgesagten Run können keine Party-Finder-Infos veröffentlicht werden.',
            ],
            'queue' => [
                'modal' => [
                    'character_refresh_failed' => 'Charakterdaten konnten gerade nicht aktualisiert werden.',
                    'character_refresh_not_found' => 'Dieser Charakter wurde auf Lodestone nicht gefunden.',
                    'character_refresh_unavailable' => 'Diese Bewerbung hat keinen Charakter, der aktualisiert werden kann.',
                    'character_refresh_cooldown' => 'Dieser Charakter wurde kürzlich geprüft. Versuche es in ein paar Minuten erneut.',
                ],
            ],
        ],
    ],
    'membership_applications' => [
        'apply' => [
            'validation' => [
                'pending_exists' => 'Du hast bereits eine offene Anfrage für diese Gruppe.',
            ],
        ],
        'review' => [
            'validation' => [
                'already_reviewed' => 'Diese Anfrage wurde bereits geprüft.',
                'applicant_banned' => 'Gesperrte Benutzer können nicht in die Gruppe aufgenommen werden.',
            ],
        ],
        'form' => [
            'validation' => [
                'fields_required' => 'Gib mindestens eine Frage an.',
                'minimum_fields' => 'Anfrageformulare benötigen mindestens eine Frage.',
                'max_fields' => 'Anfrageformulare können bis zu :max Fragen haben.',
                'field_invalid' => 'Jede Frage muss ein gültiges Objekt sein.',
                'type_invalid' => 'Wähle einen gültigen Eingabetyp.',
                'name_required' => 'Der englische Name ist erforderlich.',
                'localized_text_invalid' => 'Lokalisierter Text muss Text sein.',
                'localized_text_max' => 'Lokalisierter Text darf höchstens :max Zeichen lang sein.',
                'options_required' => 'Auswahlfragen benötigen mindestens eine Option.',
                'options_max' => 'Auswahlfragen können bis zu :max Optionen haben.',
                'option_invalid' => 'Jede Option muss ein gültiges Objekt sein.',
                'answer_unknown' => 'Diese Antwort passt nicht zum aktuellen Anfrageformular.',
                'answer_required' => 'Diese Antwort ist erforderlich.',
                'answer_invalid' => 'Gib eine gültige Antwort an.',
                'answer_max' => 'Diese Antwort darf höchstens :max Zeichen lang sein.',
                'answer_option_invalid' => 'Wähle eine der verfügbaren Optionen.',
            ],
        ],
    ],
];
