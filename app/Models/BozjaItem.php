<?php

namespace App\Models;

use App\Support\Bozja\BozjaItemCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BozjaItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'category',
        'name',
        'description',
        'classification',
        'cache_weight',
        'icon_url',
        'source_payload',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'name' => 'array',
        'description' => 'array',
        'source_payload' => 'array',
        'cache_weight' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy('sort_order')
            ->orderBy('key');
    }

    public function scopeForCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeForSource(Builder $query, string $source): Builder
    {
        $category = BozjaItemCategory::categoryForSource($source);

        return $category === null
            ? $query->whereRaw('1 = 0')
            : $query->forCategory($category);
    }

    public static function supportsSource(?string $source): bool
    {
        return BozjaItemCategory::categoryForSource($source) !== null;
    }

    public function localizedName(?string $locale = null): string
    {
        $names = $this->name ?? [];
        $locale ??= app()->getLocale();

        return (string) ($names[$locale] ?? $names['en'] ?? reset($names) ?: $this->key);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function schemaOptions(string $source): array
    {
        if (! self::supportsSource($source)) {
            return [];
        }

        return self::query()
            ->forSource($source)
            ->active()
            ->ordered()
            ->get()
            ->map(fn (self $item) => [
                'key' => (string) $item->id,
                'label' => $item->name,
                'meta' => [
                    'icon_url' => $item->icon_url,
                    'description' => $item->description,
                    'classification' => $item->classification,
                    'cache_weight' => $item->cache_weight,
                ],
            ])
            ->values()
            ->all();
    }
}
