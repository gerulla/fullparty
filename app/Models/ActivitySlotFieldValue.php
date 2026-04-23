<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivitySlotFieldValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'activity_slot_id',
        'field_key',
        'field_label',
        'field_type',
        'source',
        'value',
    ];

    protected $casts = [
        'field_label' => 'array',
        'value' => 'array',
    ];

    public function activitySlot(): BelongsTo
    {
        return $this->belongsTo(ActivitySlot::class);
    }
}
