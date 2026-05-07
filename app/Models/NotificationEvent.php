<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NotificationEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'category',
        'is_mandatory',
        'actor_user_id',
        'subject_type',
        'subject_id',
        'title_key',
        'body_key',
        'message_params',
        'action_url',
        'payload',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'message_params' => 'array',
        'payload' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class)->latest();
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class)->latest();
    }

    public function systemBroadcast(): HasOne
    {
        return $this->hasOne(SystemNotificationBroadcast::class);
    }
}
