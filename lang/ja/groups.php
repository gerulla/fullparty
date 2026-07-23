<?php

return [
    'activities' => [
        'management' => [
            'messages' => [
                'application_cancelled_assignment' => 'この申請はキャンセルされたため、キューから削除されました。',
                'application_no_longer_pending_assignment' => 'この申請は保留中ではなくなったため、キューから削除されました。',
                'missing_application_cancelled' => 'このプレイヤーは参加登録をキャンセルしたため、欠席エントリーを削除しました。',
                'party_finder_archived' => '完了またはキャンセルされた開催にはパーティ募集情報を公開できません。',
            ],
            'queue' => [
                'modal' => [
                    'character_refresh_failed' => '現在、キャラクターデータを更新できません。',
                    'character_refresh_not_found' => 'このキャラクターはLodestoneで見つかりませんでした。',
                    'character_refresh_unavailable' => 'この申請には更新できるキャラクターがありません。',
                    'character_refresh_cooldown' => 'このキャラクターは最近確認されています。数分後にもう一度お試しください。',
                ],
            ],
        ],
    ],
    'membership_applications' => [
        'apply' => [
            'validation' => [
                'pending_exists' => 'このグループには既に保留中のリクエストがあります。',
            ],
        ],
        'review' => [
            'validation' => [
                'already_reviewed' => 'このリクエストは既に確認済みです。',
                'applicant_banned' => '禁止されたユーザーはグループに承認できません。',
            ],
        ],
        'form' => [
            'validation' => [
                'fields_required' => '少なくとも1つの質問を追加してください。',
                'minimum_fields' => 'リクエストフォームには少なくとも1つの質問が必要です。',
                'max_fields' => 'リクエストフォームの質問は:max件までです。',
                'field_invalid' => '各質問は有効なオブジェクトである必要があります。',
                'type_invalid' => '有効な入力タイプを選択してください。',
                'name_required' => '英語名は必須です。',
                'localized_text_invalid' => 'ローカライズされたテキストは文字列である必要があります。',
                'localized_text_max' => 'ローカライズされたテキストは:max文字以内で入力してください。',
                'options_required' => '選択式の質問には少なくとも1つの選択肢が必要です。',
                'options_max' => '選択式の質問の選択肢は:max件までです。',
                'option_invalid' => '各選択肢は有効なオブジェクトである必要があります。',
                'answer_unknown' => 'この回答は現在のリクエストフォームと一致しません。',
                'answer_required' => 'この回答は必須です。',
                'answer_invalid' => '有効な回答を入力してください。',
                'answer_max' => 'この回答は:max文字以内で入力してください。',
                'answer_option_invalid' => '利用可能な選択肢から選んでください。',
            ],
        ],
    ],
];
