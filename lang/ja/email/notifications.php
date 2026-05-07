<?php

return [
    'assignments' => [
        'roster_published_assigned' => [
            'title' => 'ロスターが公開されました',
            'body' => ':activity のロスターが公開されました。あなたは :character として :slot に割り当てられています。',
        ],
        'roster_published_bench' => [
            'title' => 'ロスターが公開されました',
            'body' => ':activity のロスターが公開されました。あなたは :character としてベンチです。',
        ],
        'assigned' => [
            'title' => 'ロスター割り当てが更新されました',
            'body' => 'あなたは :activity で :character として :slot に割り当てられました。',
        ],
        'on_bench' => [
            'title' => 'ベンチ割り当てが更新されました',
            'body' => 'あなたは :activity で :character としてベンチになりました。',
        ],
        'returned_to_queue' => [
            'title' => '割り当てが審査に戻されました',
            'body' => ':activity への :character としての申請は審査キューに戻されました。',
        ],
    ],
    'user' => [
        'settings' => [
            'notifications_updated' => [
                'title' => '通知設定を更新しました',
                'body' => '通知設定が更新されました。変更された設定: :settings',
            ],
            'username_updated' => [
                'title' => 'アカウント設定を更新しました',
                'body' => 'アカウント設定が更新されました。変更された設定: :settings',
            ],
            'privacy_updated' => [
                'title' => 'プライバシー設定を更新しました',
                'body' => 'プライバシー設定が更新されました。変更された設定: :settings',
            ],
        ],
    ],
];
