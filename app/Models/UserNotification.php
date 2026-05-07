<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'notification_event_id',
        'user_id',
        'aggregate_key',
        'aggregate_count',
        'read_at',
    ];

    protected $casts = [
        'aggregate_count' => 'integer',
        'read_at' => 'datetime',
    ];

    public function notificationEvent(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markAsRead(): void
    {
        if ($this->read_at !== null) {
            return;
        }

        $this->update([
            'read_at' => now(),
        ]);
    }
}
