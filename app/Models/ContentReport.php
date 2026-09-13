<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentReport extends Model
{
    public const REASONS = ['harassment', 'hate', 'sexual_content', 'violence', 'spam', 'privacy', 'scam', 'other'];

    protected $guarded = ['id'];

    protected $hidden = ['evidence_path', 'guest_fingerprint'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function moderationCase(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class);
    }
}
