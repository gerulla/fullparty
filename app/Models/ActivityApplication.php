<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ActivityApplication extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ON_BENCH = 'on_bench';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_ON_BENCH,
        self::STATUS_DECLINED,
        self::STATUS_CANCELLED,
        self::STATUS_WITHDRAWN,
    ];

    protected $fillable = [
        'activity_id',
        'user_id',
        'selected_character_id',
        'applicant_lodestone_id',
        'applicant_character_name',
        'applicant_world',
        'applicant_datacenter',
        'applicant_avatar_url',
        'guest_access_token',
        'status',
        'notes',
        'reviewed_by_user_id',
        'submitted_at',
        'reviewed_at',
        'review_reason',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function selectedCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'selected_character_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ActivityApplicationAnswer::class);
    }

    public static function generateGuestAccessToken(): string
    {
        do {
            $token = Str::random(40);
        } while (self::query()->where('guest_access_token', $token)->exists());

        return $token;
    }
}
