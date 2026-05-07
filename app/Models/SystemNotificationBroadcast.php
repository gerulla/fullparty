<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SystemNotificationBroadcast extends Model
{
    use HasFactory;

    protected $fillable = [
        'notification_event_id',
    ];

    public function notificationEvent(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class);
    }

    public function reads(): HasMany
    {
        return $this->hasMany(SystemNotificationBroadcastRead::class)->latest();
    }
}
