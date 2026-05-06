<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Activity extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PLANNED = 'planned';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_UPCOMING = 'upcoming';
    public const STATUS_ONGOING = 'ongoing';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PLANNED,
        self::STATUS_SCHEDULED,
        self::STATUS_ASSIGNED,
        self::STATUS_UPCOMING,
        self::STATUS_ONGOING,
        self::STATUS_COMPLETE,
        self::STATUS_CANCELLED,
    ];

    public const ARCHIVED_STATUSES = [
        self::STATUS_COMPLETE,
        self::STATUS_CANCELLED,
    ];

    public const ASSIGNABLE_STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_SCHEDULED,
    ];

    public const COMPLETABLE_STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_SCHEDULED,
        self::STATUS_ASSIGNED,
        self::STATUS_UPCOMING,
        self::STATUS_ONGOING,
    ];

    protected $fillable = [
        'group_id',
        'activity_type_id',
        'activity_type_version_id',
        'organized_by_user_id',
        'organized_by_character_id',
        'status',
        'title',
        'description',
        'notes',
        'starts_at',
        'duration_hours',
        'target_prog_point_key',
        'is_public',
        'needs_application',
        'allow_guest_applications',
        'secret_key',
        'settings',
        'progress_entry_mode',
        'progress_link_url',
        'progress_notes',
        'furthest_progress_key',
        'furthest_progress_percent',
        'is_completed',
        'completed_at',
        'progress_recorded_by_user_id',
        'progress_recorded_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'duration_hours' => 'integer',
        'is_public' => 'boolean',
        'needs_application' => 'boolean',
        'allow_guest_applications' => 'boolean',
        'settings' => 'array',
        'furthest_progress_percent' => 'decimal:2',
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
        'progress_recorded_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function activityType(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class);
    }

    public function activityTypeVersion(): BelongsTo
    {
        return $this->belongsTo(ActivityTypeVersion::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organized_by_user_id');
    }

    public function organizerCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'organized_by_character_id');
    }

    public function progressRecorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'progress_recorded_by_user_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(ActivitySlot::class)->orderBy('sort_order');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ActivityApplication::class);
    }

    public function progressMilestones(): HasMany
    {
        return $this->hasMany(ActivityProgressMilestone::class)->orderBy('sort_order');
    }

    public function slotAssignments(): HasMany
    {
        return $this->hasMany(ActivitySlotAssignment::class)->latest('assigned_at');
    }

    public function isArchived(): bool
    {
        return in_array($this->status, self::ARCHIVED_STATUSES, true);
    }

    public static function isArchivedStatus(?string $status): bool
    {
        return in_array($status, self::ARCHIVED_STATUSES, true);
    }

    public function canBeCancelled(): bool
    {
        return !$this->isArchived();
    }

    public function canBeDeleted(): bool
    {
        return $this->status === self::STATUS_PLANNED;
    }

    public function canBeMarkedAssigned(): bool
    {
        return in_array($this->status, self::ASSIGNABLE_STATUSES, true);
    }

    public function canBeCompleted(): bool
    {
        return in_array($this->status, self::COMPLETABLE_STATUSES, true);
    }

    public static function generateSecretKey(): string
    {
        do {
            $key = Str::random(40);
        } while (self::query()->where('secret_key', $key)->exists());

        return $key;
    }
}
