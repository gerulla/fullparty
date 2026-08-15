<?php

return [
    'deleted_subject' => '削除済みのユーザーまたはグループ (#:id)',
    'validation' => [
        'unsupported_subject' => 'この上限の対象種別には対応していません。',
        'scope_mismatch' => '選択した上限はこの対象種別には適用できません。',
    ],
    'labels' => [
        'groups_owned' => '所有グループ数',
        'groups_joined' => '参加グループ数',
        'runs_per_day' => '予定日ごとの募集数',
        'runs_future' => '今後の募集数',
        'characters_total' => '登録キャラクター数',
        'applications_active' => '有効な参加申請数',
        'membership_applications_pending' => '審査中のグループ参加申請数',
        'invites_active' => '有効な招待リンク数',
        'holsters_total' => 'ホルスター数',
        'phantom_compositions_total' => 'ファントムジョブ構成数',
        'member_notes_per_member' => 'メンバーごとのメモ数',
        'member_note_addenda_per_note' => 'メモごとの返信数',
        'run_list_groups_total' => '「自分の募集」のグループ数',
    ],
    'messages' => [
        'groups_owned' => '所有できるグループは最大:limit件です。',
        'groups_joined' => '参加できるグループは最大:limit件です。',
        'runs_per_day' => 'このグループが同じ日に予定できる募集は最大:limit件です。',
        'runs_future' => 'このグループが登録できる今後の募集は最大:limit件です。',
        'characters_total' => '登録できるキャラクターは最大:limit人です。',
        'applications_active' => '有効な参加申請は最大:limit件です。',
        'membership_applications_pending' => '審査中のグループ参加申請は最大:limit件です。',
        'invites_active' => 'このグループが作成できる有効な招待リンクは最大:limit件です。',
        'holsters_total' => 'このグループが作成できるホルスターは最大:limit件です。',
        'phantom_compositions_total' => 'このグループが作成できるファントムジョブ構成は最大:limit件です。',
        'member_notes_per_member' => 'メンバー1人につき保存できるメモは最大:limit件です。',
        'member_note_addenda_per_note' => 'メモへの返信は最大:limit件です。',
        'run_list_groups_total' => '「自分の募集」に追加できるグループは最大:limit件です。',
    ],
];
