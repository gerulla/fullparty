<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemNotificationBroadcastRead extends Model
{
    use HasFactory;

    protected $fillable = [
        'system_notification_broadcast_id',
        'user_id',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(SystemNotificationBroadcast::class, 'system_notification_broadcast_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
