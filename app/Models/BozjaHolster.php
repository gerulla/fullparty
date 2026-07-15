<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use InvalidArgumentException;

class BozjaHolster extends Model
{
    use HasFactory;

    public const DEFAULT_MAX_CAPACITY = 99;

    public const MAX_CAPACITY = 99;

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
        'max_capacity',
        'notes',
        'guide',
        'is_active',
        'is_default',
    ];

    protected $attributes = [
        'max_capacity' => self::DEFAULT_MAX_CAPACITY,
        'is_active' => true,
        'is_default' => false,
    ];

    protected $casts = [
        'name' => 'array',
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

    protected function maxCapacity(): Attribute
    {
        return Attribute::make(
            set: function (int $value): int {
                if ($value < 1 || $value > self::MAX_CAPACITY) {
                    throw new InvalidArgumentException('A Bozja holster capacity must be between 1 and 99.');
                }

                return $value;
            },
        );
    }

    public function localizedName(?string $locale = null): ?string
    {
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
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(fn (self $holster) => [
                'key' => (string) $holster->id,
                'label' => array_filter($holster->name ?? [], fn ($name) => filled($name))
                    ?: ['en' => "Holster #{$holster->id}"],
                'meta' => null,
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
