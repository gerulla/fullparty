<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterTransferRun extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['slot_ids' => 'array', 'assignment_ids' => 'array'];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(CharacterTransfer::class, 'character_transfer_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
