<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class BozjaHolster extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $holster) {
            if ($holster->moderation_hidden_at) {
                $holster->is_active = false;
            }
        });
    }

    public const DEFAULT_MAX_CAPACITY = 99;

    public const MAX_CAPACITY = 99;

    public const TYPE_PREPOP = 'prepop';

    public const TYPE_REFILL = 'refill';

    public const TYPES = [
        self::TYPE_PREPOP,
        self::TYPE_REFILL,
    ];

    public const ROLES = [
        'tank',
        'healer',
        'melee dps',
        'physical ranged dps',
        'magic ranged dps',
    ];

    protected $fillable = [
        'group_id',
        'name',
        'role',
        'type',
        'parent_holster_id',
        'max_capacity',
        'notes',
        'guide',
        'is_active',
        'is_default',
    ];

    protected $attributes = [
        'max_capacity' => self::DEFAULT_MAX_CAPACITY,
        'type' => self::TYPE_PREPOP,
        'is_active' => true,
        'is_default' => false,
    ];

    protected $casts = [
        'name' => 'array',
        'parent_holster_id' => 'integer',
        'max_capacity' => 'integer',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(BozjaItem::class, 'bozja_holster_items')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    public function parentHolster(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_holster_id');
    }

    public function refillHolsters(): HasMany
    {
        return $this->hasMany(self::class, 'parent_holster_id');
    }

    protected function maxCapacity(): Attribute
    {
        return Attribute::make(
            set: function (int $value): int {
                if ($value < 1 || $value > self::MAX_CAPACITY) {
                    throw new InvalidArgumentException(__('errors.a_bozja_holster_capacity_must_be_between_1_and_99'));
                }

                return $value;
            },
        );
    }

    protected function guide(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => ($attributes['guide_format'] ?? 'markdown') === 'tiptap' && $value !== null
                ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value,
            set: fn ($value) => [
                'guide' => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : $value,
                'guide_format' => is_string($value) ? 'markdown' : 'tiptap',
            ],
        );
    }

    public function localizedName(?string $locale = null): ?string
    {
        if ($this->moderation_hidden_at) {
            return __('reports.hidden_content', [], $locale);
        }
        $names = array_filter($this->name ?? [], fn ($name) => filled($name));
        $locale ??= app()->getLocale();
        $name = $names[$locale] ?? $names['en'] ?? reset($names);

        return $name === false ? null : (string) $name;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function schemaOptionsForGroup(int $groupId): array
    {
        return self::query()
            ->where('group_id', $groupId)
            ->where('is_active', true)
            ->where(function ($query) use ($groupId) {
                $query->where('type', self::TYPE_PREPOP)
                    ->orWhereHas('parentHolster', fn ($parentQuery) => $parentQuery
                        ->where('group_id', $groupId)
                        ->where('is_active', true)
                        ->where('type', self::TYPE_PREPOP));
            })
            ->with(['items' => fn ($query) => $query
                ->orderBy('bozja_items.sort_order')
                ->orderBy('bozja_items.key')])
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(fn (self $holster) => [
                'key' => (string) $holster->id,
                'label' => array_filter($holster->name ?? [], fn ($name) => filled($name))
                    ?: ['en' => "Holster #{$holster->id}"],
                'meta' => [
                    'holster_type' => $holster->type,
                    'parent_holster_id' => $holster->parent_holster_id,
                    'role' => $holster->role,
                    'notes' => $holster->notes,
                    'capacity_used' => $holster->capacity_used,
                    'max_capacity' => $holster->max_capacity,
                    'items' => $holster->items
                        ->map(fn (BozjaItem $item) => [
                            'key' => (string) $item->id,
                            'label' => $item->name,
                            'icon_url' => $item->icon_url,
                            'quantity' => (int) $item->pivot->quantity,
                            'cache_weight' => $item->cache_weight,
                        ])
                        ->values()
                        ->all(),
                ],
            ])
            ->values()
            ->all();
    }

    public function getCapacityUsedAttribute(): int
    {
        $items = $this->relationLoaded('items')
            ? $this->items
            : $this->items()->get();

        return $items->sum(
            fn (BozjaItem $item) => $item->cache_weight * (int) $item->pivot->quantity,
        );
    }
}
