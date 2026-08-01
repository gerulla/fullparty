<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhantomComposition extends Model
{
    // Keep the legacy stored value so Blood and Magic share existing compositions.
    public const CONTENT_FORKED_TOWER = 'forked_tower_blood';

    public const CONTENT_FORKED_TOWER_BLOOD = self::CONTENT_FORKED_TOWER;

    public const RULE_SINGLE_JOB_COUNT = 'single_job_count';

    public const RULE_JOB_SET_TOTAL = 'job_set_total';

    public const RULE_EACH_JOB_IN_SET = 'each_job_in_set';

    public const RULE_ANY_JOB_IN_SET = 'any_job_in_set';

    public const RULE_DUPLICATE_LIMIT = 'duplicate_limit';

    public const RULE_PACKAGE = 'package';

    public const SEVERITY_REQUIRED = 'required';

    public const SEVERITY_RECOMMENDED = 'recommended';

    public const SEVERITY_OPTIONAL = 'optional';

    public const COMPARISON_AT_LEAST = 'at_least';

    public const COMPARISON_EXACTLY = 'exactly';

    public const COMPARISON_AT_MOST = 'at_most';

    public const SCOPE_ALL_SLOTS = 'all_slots';

    public const SCOPE_SLOT_GROUP = 'slot_group';

    public const SCOPE_SLOT_GROUP_SET = 'slot_group_set';

    public const SCOPE_EACH_SLOT_GROUP = 'each_slot_group';

    public const SCOPE_EACH_SLOT_GROUP_SET = 'each_slot_group_set';

    public const STATE_MET = 'met';

    public const STATE_UNMET = 'unmet';

    public const STATE_PARTIAL = 'partial';

    public const STATE_OVERFILLED = 'overfilled';

    public const STATE_NOT_APPLICABLE = 'not_applicable';

    protected $fillable = [
        'group_id',
        'content_key',
        'name',
        'description',
        'is_default',
        'is_active',
        'sort_order',
        'rules',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'rules' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return array<int, string>
     */
    public static function contentKeys(): array
    {
        return [
            self::CONTENT_FORKED_TOWER,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function ruleTypes(): array
    {
        return [
            self::RULE_SINGLE_JOB_COUNT,
            self::RULE_JOB_SET_TOTAL,
            self::RULE_EACH_JOB_IN_SET,
            self::RULE_ANY_JOB_IN_SET,
            self::RULE_DUPLICATE_LIMIT,
            self::RULE_PACKAGE,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function severities(): array
    {
        return [
            self::SEVERITY_REQUIRED,
            self::SEVERITY_RECOMMENDED,
            self::SEVERITY_OPTIONAL,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function comparisons(): array
    {
        return [
            self::COMPARISON_AT_LEAST,
            self::COMPARISON_EXACTLY,
            self::COMPARISON_AT_MOST,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function scopeTypes(): array
    {
        return [
            self::SCOPE_ALL_SLOTS,
            self::SCOPE_SLOT_GROUP,
            self::SCOPE_SLOT_GROUP_SET,
            self::SCOPE_EACH_SLOT_GROUP,
            self::SCOPE_EACH_SLOT_GROUP_SET,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function states(): array
    {
        return [
            self::STATE_MET,
            self::STATE_UNMET,
            self::STATE_PARTIAL,
            self::STATE_OVERFILLED,
            self::STATE_NOT_APPLICABLE,
        ];
    }

    /**
     * @return array<int, array{key: string, label: array<string, string>}>
     */
    public static function slotGroupsForContent(string $contentKey): array
    {
        if ($contentKey !== self::CONTENT_FORKED_TOWER) {
            return [];
        }

        return collect(range('a', 'f'))
            ->map(fn (string $letter) => [
                'key' => 'party-'.$letter,
                'label' => [
                    'en' => 'Party '.strtoupper($letter),
                    'de' => 'Gruppe '.strtoupper($letter),
                    'fr' => 'Equipe '.strtoupper($letter),
                    'ja' => 'PT '.strtoupper($letter),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{key: string, label: array<string, string>, group_keys: array<int, string>}>
     */
    public static function defaultGroupSetsForContent(string $contentKey): array
    {
        if ($contentKey !== self::CONTENT_FORKED_TOWER) {
            return [];
        }

        return [
            [
                'key' => 'side-one',
                'label' => ['en' => 'Side 1'],
                'group_keys' => ['party-a', 'party-b', 'party-c'],
            ],
            [
                'key' => 'side-two',
                'label' => ['en' => 'Side 2'],
                'group_keys' => ['party-d', 'party-e', 'party-f'],
            ],
        ];
    }
}
