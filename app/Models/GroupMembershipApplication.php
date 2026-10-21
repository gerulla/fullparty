<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupMembershipApplication extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_DECLINED,
        self::STATUS_CANCELLED,
    ];

    public const REVIEW_REASON_MAX_LENGTH = 2000;

    protected $fillable = [
        'group_id',
        'user_id',
        'status',
        'answers',
        'form_snapshot',
        'reviewed_by_user_id',
        'submitted_at',
        'reviewed_at',
        'review_reason',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'answers' => 'array',
        'form_snapshot' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
