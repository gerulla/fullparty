<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterFieldValue extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'character_id',
        'character_field_definition_id',
        'value',
    ];

    /**
     * Get the character that owns this field value.
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /**
     * Get the field definition for this value.
     */
    public function fieldDefinition(): BelongsTo
    {
        return $this->belongsTo(CharacterFieldDefinition::class);
    }

    /**
     * Get the value casted to the appropriate type based on field definition.
     */
    public function getCastedValue(): mixed
    {
        if (!$this->fieldDefinition) {
            return $this->value;
        }

        return match ($this->fieldDefinition->type) {
            'number' => is_numeric($this->value) ? (float) $this->value : null,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            default => $this->value,
        };
    }

    /**
     * Set the value with automatic type handling.
     */
    public function setCastedValue(mixed $value): void
    {
        $this->value = match ($this->fieldDefinition?->type) {
            'boolean' => $value ? '1' : '0',
            'number' => (string) $value,
            default => (string) $value,
        };
    }
}
