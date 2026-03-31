<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Character extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
		'is_primary',
        'name',
        'world',
        'datacenter',
        'lodestone_id',
        'avatar_url',
        'token',
        'expires_at',
        'verified_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    /**
     * Get the user that owns the character.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the field values for the character.
     */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(CharacterFieldValue::class);
    }

    /**
     * Check if character is verified.
     */
    public function isVerified(): bool
    {
        return !is_null($this->verified_at);
    }

    /**
     * Check if verification token has expired.
     */
    public function isTokenExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Get a specific field value by slug.
     */
    public function getFieldValue(string $slug): mixed
    {
        $fieldValue = $this->fieldValues()
            ->whereHas('fieldDefinition', function ($query) use ($slug) {
                $query->where('slug', $slug);
            })
            ->with('fieldDefinition')
            ->first();

        if (!$fieldValue) {
            return null;
        }

        return $fieldValue->getCastedValue();
    }

    /**
     * Set a field value by slug.
     */
    public function setFieldValue(string $slug, mixed $value): void
    {
        $fieldDefinition = CharacterFieldDefinition::where('slug', $slug)->firstOrFail();

        $this->fieldValues()->updateOrCreate(
            ['character_field_definition_id' => $fieldDefinition->id],
            ['value' => $value]
        );
    }
}
