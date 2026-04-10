<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CharacterFieldDefinition extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'type',
        'description',
        'group',
        'display_contexts',
        'source_type',
        'is_editable',
        'is_visible',
        'tags',
        'validation_rules',
        'sort_order',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'display_contexts' => 'array',
        'tags' => 'array',
        'validation_rules' => 'array',
        'is_editable' => 'boolean',
        'is_visible' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Get the field values for this definition.
     */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(CharacterFieldValue::class);
    }

    /**
     * Scope to only active field definitions.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to order by sort order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
