<?php

return [
    'fflogs_auth_title' => 'FF Logsの認証に失敗しました',
    'fflogs_auth_message' => 'トークンの自動更新後もFullPartyはFF Logsに認証できませんでした。FF Logsの認証情報と、管理パネル → FF Logs Playgroundを確認してください。',
    'fflogs_connection_title' => 'FF Logsへの接続に失敗しました',
    'fflogs_connection_message' => 'FullPartyはFF Logsに接続できませんでした。進行状況を確認できない可能性があります。ネットワーク接続とFF Logsのサービス状況を確認してください。',
    'fflogs_unavailable_title' => 'FF Logs APIを利用できません',
    'fflogs_unavailable_message' => 'FF LogsがHTTP :statusを返しました。進行状況を確認できない可能性があります。管理パネル → FF Logs Playgroundで詳細を確認してください。',
    'fflogs_quota_title' => 'FF Logs APIの利用上限に近づいています',
    'fflogs_quota_message' => 'FullPartyはこの1時間に:limitポイント中:spentポイントを使用しました。残り:seconds秒で利用枠がリセットされます。リクエストが制限される前にAPIの使用状況を確認してください。',
    'fflogs_health_title' => 'FF Logsの稼働確認に失敗しました',
    'fflogs_health_message' => '定期的なFF Logsの確認で、有効なAPI使用状況を取得できませんでした。管理パネル → FF Logs Playgroundを確認してください。',
    'integration_delivery_title' => '連携イベントの送信に失敗しました',
    'integration_delivery_message' => '連携:clientは:eventを受信できませんでした。管理パネル → 連携で詳細を確認してください。',
    'integration_health_title' => '連携の稼働状況を確認してください',
    'integration_health_message' => '連携:clientがステータス:statusを報告しました。管理パネル → 連携で詳細を確認してください。',
    'test_title' => 'FullParty管理者レポートのテスト',
    'test_message' => 'これはFullPartyからのdiscord.admin.reportイベントのテストです。実際の障害によるメッセージではありません。',
    'test_disabled' => '管理者レポートは無効です。このテストを有効にするにはDISCORD_ADMIN_REPORTS_ENABLED=trueを設定してください。',
    'test_failed' => 'テストレポートを送信できませんでした。有効なボット、discord.admin.reportの権限、連携の送信診断を確認してください。',
    'test_sent' => 'ボットがテストレポートを受け付けました。サイト管理者のDiscord DMを確認してください。',
    'test_queued' => 'テストレポートをキューに追加しました。送信にはキューワーカーが実行中である必要があります。',
];
