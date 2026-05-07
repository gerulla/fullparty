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
        'social_account' => [
            'linked' => [
                'title' => '連携アカウントを追加しました',
                'body' => ':provider アカウントが FullParty に連携されました。',
            ],
            'unlinked' => [
                'title' => '連携アカウントを解除しました',
                'body' => ':provider アカウントの連携を FullParty から解除しました。',
            ],
        ],
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
    'characters' => [
        'added' => [
            'title' => 'キャラクターを追加しました',
            'body' => ':character (:world / :datacenter) を :method 経由でアカウントに追加しました。',
        ],
        'refreshed' => [
            'title' => 'キャラクターを更新しました',
            'body' => ':character (:world / :datacenter) のプロフィール情報を最新の状態に更新しました。',
        ],
        'primary_changed' => [
            'title' => 'メインキャラクターを更新しました',
            'body' => ':character (:world / :datacenter) がメインキャラクターになりました。',
        ],
        'unclaimed' => [
            'title' => 'キャラクターの紐付けを解除',
            'body' => ':character (:world / :datacenter) はアカウントから解除されました。',
        ],
    ],
];
